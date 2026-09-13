<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Postgres;

use Doctrine\DBAL\Connection;
use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Service\Process\PgPassFile;
use Vortos\Backup\Service\Process\ProcessGuard;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\StreamMode;

/**
 * Spawns the Postgres dump subprocess, streaming stdout.
 *
 * Connection parameters come from the DBAL connection (primary or replica), never configured twice.
 * The password reaches the child as a 0600 `PGPASSFILE` — never argv, never the environment, never a
 * log — and the child gets a minimal environment rather than the application's.
 */
final class PostgresProcessFactory
{
    public function __construct(
        private readonly Connection $primary,
        private readonly ?Connection $replica = null,
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    /**
     * @return array{0: resource, 1: ProcessGuard} stdout stream + guard
     */
    public function pgDump(bool $fromReplica): array
    {
        $params = $this->params($fromReplica);

        return $this->spawn('pg_dump', [
            '--no-owner',
            '--no-privileges',
            '--format=custom',
            '--compress=6',
            '--no-password',
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
        $params = $this->params($fromReplica);

        return $this->spawn('pg_basebackup', [
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
     * @param list<string> $arguments
     * @return array{0: resource, 1: ProcessGuard}
     */
    private function spawn(string $binary, array $arguments, string $password): array
    {
        if ($this->launcher->which($binary) === null) {
            throw DumpFailedException::missingBinary('postgres', $binary);
        }

        $process = $this->launcher->start(
            new ProcessSpec([$binary, ...$arguments], EnvironmentPolicy::Minimal, null, env: ['PGPASSFILE' => PgPassFile::for($password)]),
            StreamMode::Null,
            StreamMode::Pipe,
            StreamMode::Capture,
        );

        return [$process->stdout(), new ProcessGuard($process, 'postgres')];
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
}
