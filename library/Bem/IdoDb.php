<?php

namespace Icinga\Module\Bem;

use Icinga\Data\ResourceFactory;
use Icinga\Module\Bem\Config\CellConfig;
use Icinga\Module\Monitoring\Backend\MonitoringBackend;
use Zend_Db_Adapter_Abstract as DbAdapter;

/**
 * Class IdoDb
 *
 * Small IDO abstraction layer
 */
class IdoDb
{
    /** @var DbAdapter */
    protected $db;

    /**
     * IdoDb constructor.
     * @param DbAdapter $db
     */
    public function __construct(DbAdapter $db)
    {
        $this->db = $db;
    }

    /**
     * @return DbAdapter
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param CellConfig $cell
     * @return object[]
     */
    public function fetchProblems(CellConfig $cell)
    {
        $objects = [];
        foreach ($this->fetchProblemHosts() as $object) {
            $objects[$object->id] = $object;
        }
        foreach ($this->fetchProblemServices() as $object) {
            $objects[$object->id] = $object;
        }

        $this->enrichRowsWithVars($objects);

        return $objects;
    }

    protected function fetchProblemHosts()
    {
        return $this->db->fetchAll(
            $this->selectHosts()
                ->where('hs.current_state > 0')
                ->where('hs.state_type = 1')
                ->where('hs.scheduled_downtime_depth = 0')
                ->where('hs.problem_has_been_acknowledged = 0')
        );
    }

    protected function fetchProblemServices()
    {
        return $this->db->fetchAll(
            $this->selectServices()
                ->where('hs.current_state = 0')
                ->where('ss.state_type = 1')
                ->where('ss.current_state > 0')
                ->where('ss.scheduled_downtime_depth = 0')
                ->where('ss.problem_has_been_acknowledged = 0')
        );
    }

    public function getStateRowFor($host, $service = null)
    {
        if ($service === null) {
            $row = $this->getHostStateRow($host);
        } else {
            $row = $this->getServiceStateRow($host, $service);
        }

        if ($row === false) {
            return false;
        }

        return $this->enrichRowWithVars($row);
    }

    public function getEmptyStateRowFor($host, $service = null)
    {
        if ($service === null) {
            $objectType = 'host';
            $state = 'UP';
            $output = "$host no longer exists";
        } else {
            $objectType = 'service';
            $state = 'OK';
            $output = "$service no longer exists on $host";
        }

        return (object) [
            'id'              => null,
            'object_type'     => $objectType,
            'host_id'         => null,
            'host_name'       => $host,
            'service_name'    => $service,
            'state_type'      => 'SOFT',
            'state'           => $state,
            'hard_state'      => $state,
            'is_acknowledged' => 0,
            'is_in_downtime'  => 0,
            'output'          => $output
        ];
    }

    public function getHostStateRow($host)
    {
        return $this->db->fetchRow(
            $this->selectHosts()->where('ho.name1 = ?', $host)
        );
    }

    public function getServiceStateRow($host, $service)
    {
        return $this->db->fetchRow(
            $this->selectServices()
                ->where('so.name1 = ?', $host)
                ->where('so.name2 = ?', $service)
        );
    }

    protected function selectHosts()
    {
        return $this->db->select()->from(
            ['ho' => 'icinga_objects'],
            [
                'id'              => 'ho.object_id',
                'object_type'     => "('host')",
                'host_id'         => '(NULL)',
                'host_name'       => 'ho.name1',
                'service_name'    => '(NULL)',
                'state_type'      => "(CASE WHEN hs.state_type = 1 THEN 'HARD' ELSE 'SOFT' END)",
                'state'           => '(CASE hs.current_state'
                    . " WHEN 0 THEN 'UP'"
                    . " WHEN 2 THEN 'UNREACHABLE'"
                    . " ELSE 'DOWN'"
                    . " END)",
                'hard_state'      => 'CASE WHEN hs.has_been_checked = 0 OR hs.has_been_checked IS NULL THEN 99'
                    . ' ELSE CASE WHEN hs.state_type = 1 THEN hs.current_state'
                    . ' ELSE hs.last_hard_state END END',
                'is_acknowledged' => 'hs.problem_has_been_acknowledged',
                'is_in_downtime'  => 'CASE WHEN (hs.scheduled_downtime_depth = 0) THEN 0 ELSE 1 END',
                'output'          => 'hs.output',
            ]
        )->join(
            ['hs' => 'icinga_hoststatus'],
            'ho.object_id = hs.host_object_id AND ho.is_active = 1',
            []
        );
    }

    protected function selectServices()
    {
        return $this->db->select()->from(
            ['so' => 'icinga_objects'],
            [
                'id'              => 'so.object_id',
                'object_type'     => "('service')",
                'host_id'         => 'hs.host_object_id',
                'host_name'       => 'so.name1',
                'service_name'    => 'so.name2',
                'state_type'      => "(CASE WHEN ss.state_type = 1 THEN 'HARD' ELSE 'SOFT' END)",
                'state'           => '(CASE ss.current_state'
                    . " WHEN 0 THEN 'OK'"
                    . " WHEN 1 THEN 'WARNING'"
                    . " WHEN 2 THEN 'CRITICAL'"
                    . " ELSE 'UNKNOWN'"
                    . " END)",
                'hard_state'      => 'CASE WHEN ss.has_been_checked = 0 OR ss.has_been_checked IS NULL THEN 99'
                    . ' ELSE CASE WHEN ss.state_type = 1 THEN ss.current_state'
                    . ' ELSE ss.last_hard_state END END',
                'is_acknowledged' => 'ss.problem_has_been_acknowledged',
                'is_in_downtime'  => 'CASE WHEN (ss.scheduled_downtime_depth = 0) THEN 0 ELSE 1 END',
                'output'          => 'ss.output',
            ]
        )->join(
            ['ss' => 'icinga_servicestatus'],
            'so.object_id = ss.service_object_id AND so.is_active = 1',
            []
        )->join(
            ['s' => 'icinga_services'],
            's.service_object_id = ss.service_object_id',
            []
        )->join(
            ['hs' => 'icinga_hoststatus'],
            'hs.host_object_id = s.host_object_id',
            []
        );
    }

    protected function enrichRowWithVars($row)
    {
        if ($row->object_type === 'host') {
             $this->enrichWithVars($row, $row->id, 'host.vars.');
        } else {
            $this->enrichWithVars($row, $row->host_id, 'host.vars.');
            $this->enrichWithVars($row, $row->id, 'service.vars.');
        }

        return $row;
    }

    protected function enrichWithVars($row, $objectId, $prefix)
    {
        $query = $this->db->select()->from(
            ['cv' => 'icinga_customvariablestatus'],
            ['cv.varname', 'cv.varvalue']
        )->where('object_id = ?', $objectId);

        foreach ($this->db->fetchPairs($query) as $key => $value) {
            $row->{"$prefix$key"} = $value;
        }

        return $row;
    }

    protected function enrichRowsWithVars($rows)
    {
        if (empty($rows)) {
            return;
        }

        $serviceHostIds = [];
        foreach ($rows as $row) {
            if ($row->host_id) {
                if (! array_key_exists($row->host_id, $serviceHostIds)) {
                    $serviceHostIds[$row->host_id] = [];
                }
                $serviceHostIds[$row->host_id][] = $row->id;
            }
        }

        $query = $this->db->select()->from(
            ['cv' => 'icinga_customvariablestatus'],
            ['cv.object_id', 'cv.varname', 'cv.varvalue']
        )->where('object_id IN (?)', array_keys($rows));

        foreach ($this->db->fetchAll($query) as $row) {
            $key = $rows[$row->object_id]->service_name === null
                ? 'host.vars.' . $row->varname
                : 'service.vars.' . $row->varname;

            $rows[$row->object_id]->$key = $row->varvalue;
        }

        if (empty($serviceHostIds)) {
            return;
        }

        $query = $this->db->select()->from(
            ['cv' => 'icinga_customvariablestatus'],
            ['cv.object_id', 'cv.varname', 'cv.varvalue']
        )->where('object_id IN (?)', array_keys($serviceHostIds));

        foreach ($this->db->fetchAll($query) as $row) {
            $key = 'host.vars.' . $row->varname;

            foreach ($serviceHostIds[$row->object_id] as $id) {
                $rows[$id]->$key = $row->varvalue;
            }
        }
    }

    /**
     * Instantiate with a given Icinga Web 2 resource name
     *
     * @param $name
     * @return static
     */
    public static function fromResourceName($name)
    {
        return new static(
            ResourceFactory::create($name)->getDbAdapter()
        );
    }

    /**
     * Borrow the database connection from the monitoring module
     *
     * @return static
     * @throws \Icinga\Exception\ConfigurationError
     */
    public static function fromMonitoringModule()
    {
        return new static(
            MonitoringBackend::instance()->getResource()->getDbAdapter()
        );
    }
}
