<?php

declare(strict_types=1);

namespace Vortos\Metrics\Supervisor;

/**
 * Reads program state out of the local supervisord.
 *
 * Deliberately shells out to supervisorctl rather than speaking XML-RPC to the control socket
 * directly. That socket is intentionally a filesystem unix socket at chmod 0700 — reachable only by
 * the container's own user — so an external exporter sidecar could read it only by weakening it to
 * a TCP listener or sharing it out of the container. supervisorctl is already proven to work in
 * that container (the vortos-deploy worker healthcheck depends on it), needs no credential handling
 * of its own, and keeps this a purely in-container read.
 *
 * Output format, one line per program:
 *
 *   consumer-entry-approved          RUNNING   pid 123, uptime 0:10:23
 *   scheduler-daemon                 FATAL     Exited too quickly (process log may have details)
 */
final class SupervisorStatusReader
{
    private const STATUS_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly SupervisorCommandRunnerInterface $runner,
        private readonly string $configPath = '/etc/supervisord.conf',
    ) {}

    /**
     * @return list<SupervisorProcessStatus>
     */
    public function read(): array
    {
        return $this->parse($this->runner->run(
            ['supervisorctl', '-c', $this->configPath, 'status'],
            self::STATUS_TIMEOUT_SECONDS,
        ));
    }

    /**
     * @return list<SupervisorProcessStatus>
     */
    public function parse(string $output): array
    {
        $statuses = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // program, state, then a free-form description that only exists for some states.
            if (preg_match('/^(\S+)\s+([A-Z]+)(?:\s+(.*))?$/', $line, $matches) !== 1) {
                continue;
            }

            $description = $matches[3] ?? '';

            $statuses[] = new SupervisorProcessStatus(
                program: $matches[1],
                state: $matches[2],
                pid: $this->extractPid($description),
                uptimeSeconds: $this->extractUptimeSeconds($description),
            );
        }

        return $statuses;
    }

    private function extractPid(string $description): ?int
    {
        if (preg_match('/\bpid (\d+)/', $description, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Supervisor renders uptime as H:MM:SS and rolls days into the hours field rather than adding a
     * days component, so hours is unbounded.
     */
    private function extractUptimeSeconds(string $description): ?int
    {
        if (preg_match('/\buptime (\d+):(\d{2}):(\d{2})/', $description, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1] * 3600) + ((int) $matches[2] * 60) + (int) $matches[3];
    }
}
