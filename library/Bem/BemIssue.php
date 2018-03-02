<?php

namespace Icinga\Module\Bem;

use Icinga\Module\Bem\Config\CellConfig;
use Icinga\Module\Bem\Object\PropertyContainer;

class BemIssue
{
    use PropertyContainer;

    protected $defaultProperties = [
        'ci_name_checksum'      => null,
        'cell_name'             => null,
        'host_name'             => null,
        'object_name'           => null,
        'is_relevant'           => null,
        'severity'              => null,
        'worst_severity'        => null,
        'slot_set_values'       => null,
        'ts_first_notification' => null,
        'ts_last_notification'  => null,
        'ts_next_notification'  => null,
        'cnt_notifications'     => null,
    ];

    protected $slotSetValues;

    protected $hasBeenStored = false;

    protected $tableName = 'bem_issue';

    /** @var CellConfig */
    private $cell;

    protected function __construct(CellConfig $cell)
    {
        $this->cell = $cell;
    }

    public function getKey()
    {
        return $this->get('ci_name_checksum');
    }

    public static function forIcingaObject($icingaObject, CellConfig $cell)
    {
        $db = $cell->db();

        $object = new static($cell);
        $object->fillWithDefaultProperties();
        $object->setIcingaObject($icingaObject);

        $result = $db->fetchRow($object->prepareSelectQuery());
        if ($result) {
            $newProperties = $object->getPropertiesForDb();
            $object = static::forDbRow($result, $cell);
            $object->setProperties($newProperties);
        }

        return $object;
    }

    public static function load(CellConfig $cell, $host, $object)
    {
        return static::forDbRow(
            $cell->db()->fetchRow(static::prepareSelectQueryFor($cell, $host, $object)),
            $cell
        );
    }

    public static function forDbRow($row, CellConfig $cell)
    {
        $object = new static($cell);
        $object->fillWithDefaultProperties();
        if ($row) {
            $object->setProperties($row);
            $object->setUnmodified();
            $object->hasBeenStored = true;
        }

        return $object;
    }

    public function isRelevant()
    {
        return $this->get('is_relevant') === 'y';
    }

    /**
     * @return CellConfig
     */
    public function getCell()
    {
        return $this->cell;
    }

    public function isNew()
    {
        return ! $this->hasBeenStored;
    }

    public function isDueIn($dueTime)
    {
        return $this->get('ts_next_notification') <= $dueTime;
    }

    public function getUrlParams()
    {
        return [
            'host'   => $this->get('host_name'),
            'object' => $this->get('object_name'),
            'cell'   => $this->cell->getName()
        ];
    }

    public function store()
    {
        if ($this->hasBeenModified()) {
            if ($this->isNew()) {
                $this->insert();
            } else {
                $this->update();
            }
            $this->hasBeenStored = true;
        }
    }

    protected function checkWorstSeverity()
    {
        // TODO: correct implementation, even if currently unused
        $worst = $this->get('worst_severity');
        if ($worst === null) {
            $this->set('worst_severity', $this->get('severity'));
        }
    }

    protected function insert()
    {
        if ($this->cell->db()->insert(
            $this->tableName,
            $this->getPropertiesForDb()
        )) {
            $this->setUnmodified();
        }
    }

    protected function update()
    {
        $db = $this->cell->db();
        if ($db->update(
            $this->tableName,
            $this->getModifiedProperties(),
            $this->createWhere()
        )) {
            $this->setUnmodified();
        }
    }

    public function delete()
    {
        if ($this->cell->db()->delete(
            $this->tableName,
            $this->createWhere()
        )) {
            $this->hasBeenStored = false;
            foreach ($this->listProperties() as $key) {
                if ($this->get($key) !== $this->defaultProperties[$key]) {
                    $this->modifiedProperties[$key] = true;
                }
            }
        }
    }

    public function createWhere()
    {
        return $this->cell->db()->quoteInto('ci_name_checksum = ?', $this->getKey());
    }

    protected function prepareSelectQuery()
    {
        return $this->cell->db()->select()
            ->from('bem_issue')
            ->where('ci_name_checksum = ?', $this->getKey());
    }

    protected static function prepareSelectQueryFor(CellConfig $cell, $host, $object)
    {
        return $cell->db()->select()
            ->from('bem_issue')
            ->where('ci_name_checksum = ?', static::calculateChecksum(
                $cell,
                $host,
                $object
            ));
    }

    protected static function calculateChecksum(CellConfig $cell, $host, $object)
    {
        return sha1(implode('!', [
            $cell->getName(),
            $host, $object
        ]), true);
    }

    protected function recalculateCiCheckSum()
    {
        $this->set('ci_name_checksum', static::calculateChecksum(
            $this->cell,
            $this->get('host_name'),
            $this->get('object_name')
        ));
    }

    public function setIcingaObject($object)
    {
        $this->set('cell_name', $this->cell->getName());
        $this->set('host_name', $object->host_name);
        $this->set('severity', $this->cell->calculateSeverityForIcingaObject($object));
        $params = $this->cell->fillParams($object);
        $this->set('slot_set_values', json_encode($params));

        // TODO: define whether mc_host and mc_object should be required
        $this->set('host_name', $params['mc_host']);
        $this->set('object_name', $params['mc_object']);
        $this->recalculateCiCheckSum();
        $this->checkWorstSeverity();
        $this->set('is_relevant', $this->cell->wantsIcingaObject(
            $object
        ) ? 'y' : 'n');

        return $this;
    }

    public function scheduleNextNotification($timestampMs = null)
    {
        if ($timestampMs === null) {
            $timestampMs = Util::timestampWithMilliseconds();
        }

        $this->set('ts_next_notification', $timestampMs);
        if ($this->get('cnt_notifications') === null) {
            $this->set('cnt_notifications', 0);
        }

        return $this;
    }

    public function getSlotSetValues()
    {
        if ($this->slotSetValues === null) {
            $value = $this->get('slot_set_values');
            if ($value === null) {
                return [];
            } else {
                $this->slotSetValues = json_decode($value);
            }
        }

        return $this->slotSetValues;
    }
}
