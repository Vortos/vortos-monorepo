<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Postgres;

use Doctrine\DBAL\Connection;
use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Service\Process\ProcessGuard;

/**
 * Builds and spawns the Postgres dump subprocess, streaming stdout.
 *
 * Connection parameters are read from the DBAL connection (primary or replica), never
 * hand-configured twice. The password is passed via the `PGPASSWORD` environment
 * variable of the child process only — never on the argv (which is world-readable in
 * `/proc`), never logged.
 */
final class PostgresProcessFactory
{
    public function __construct(
        private readonly Connection $primary,
        private readonly ?Connection $replica = null,
    ) {}

    /**
     * @return array{0: resource, 1: ProcessGuard} stdout stream + guard
     */
    public function pgDump(bool $fromReplica): array
    {
        $this->assertBinary('pg_dump');
        $params = $this->params($fromReplica);

        return $this->spawn([
            'pg_dump',
            '--no-owner',
            '--no-privileges',
            '--format=custom',
            '--compress=6',
            '--host=' . $params['host'],
            '--port=' . (string) $params['port'],
            '--username=' . $params['user'],
            '--dbname=' . $params['dbname'],
        ], $params['password']);
    }

    /**
     * @return array{0: resource, 1: ProcessGuard}
     */
    public function pgBaseBackup(bool $fromReplica): array
    {
        $this->assertBinary('pg_basebackup');
        $params = $this->params($fromReplica);

        return $this->spawn([
            'pg_basebackup',
            '--pgdata=-',
            '--format=tar',
            '--wal-method=none',
            '--no-password',
            '--host=' . $params['host'],
            '--port=' . (string) $params['port'],
            '--username=' . $params['user'],
        ], $params['password']);
    }

    /**
     * @param list<string> $command
     * @return array{0: resource, 1: ProcessGuard}
     */
    private function spawn(array $command, string $password): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = ['PGPASSWORD' => $password, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin'];

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw DumpFailedException::reason('Failed to spawn Postgres dump process.');
        }

        return [$pipes[1], new ProcessGuard($process, $pipes[2], 'postgres')];
    }

    /**
     * @return array{host:string, port:int, user:string, password:string, dbname:string}
     */
    private function params(bool $fromReplica): array
    {
        $connection = ($fromReplica && $this->replica !== null) ? $this->replica : $this->primary;
        $p = $connection->getParams();

        return [
            'host' => (string) ($p['host'] ?? 'localhost'),
            'port' => (int) ($p['port'] ?? 5432),
            'user' => (string) ($p['user'] ?? 'postgres'),
            'password' => (string) ($p['password'] ?? ''),
            'dbname' => (string) ($p['dbname'] ?? ($p['path'] ?? 'postgres')),
        ];
    }

    private function assertBinary(string $binary): void
    {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($found === null || trim((string) $found) === '') {
            throw DumpFailedException::missingBinary('postgres', $binary);
        }
    }
}
