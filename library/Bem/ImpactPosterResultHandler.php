<?php

namespace Icinga\Module\Bem;

use React\ChildProcess\Process;

class ImpactPosterResultHandler
{
    /** @var BemNotification */
    protected $notification;

    /** @var string */
    private $outputBuffer = '';

    private $startTime;

    public function __construct(BemNotification $notification)
    {
        $this->notification = $notification;
    }

    public function start($commandLine)
    {
        $this->startTime = Util::timestampWithMilliseconds();
        $this->notification
            ->set('command_line', $commandLine)
            ->set('system_user', posix_getpwnam(posix_getuid()))
            ->set('system_host_name', gethostname());
    }

    public function stop($exitCode, $termSignal, Process $process)
    {
        $n = $this->notification;

        $n->set('pid', $process->getPid());
        $n->set('ts_notification', $this->startTime);
        $n->set('duration_ms', Util::timestampWithMilliseconds() - $this->startTime);
        if ($exitCode === null) {
            if ($termSignal === null) {
                $n->set('exit_code', 255);
            } else {
                $n->set('exit_code', 128 + $termSignal);
            }
        } else {
            $n->set('exit_code', (int) $exitCode);
        }
        $n->set('output', $this->outputBuffer);
        $n->set('bem_event_id', $this->extractEventId());

        $n->storeToLog();
    }

    public function extractEventId()
    {
        // TODO: figure out how whether we could benefit from this while streaming
        // to msend's STDIN
        if (preg_match('/Message #(\d+) - Evtid = (\d+)/', $this->outputBuffer, $match)) {
            return $match[2];
        } else {
            return null;
        }
    }

    /**
     * @param string $output
     * @return $this
     */
    public function addOutput($output)
    {
        $this->outputBuffer .= $output;

        return $this;
    }
}
