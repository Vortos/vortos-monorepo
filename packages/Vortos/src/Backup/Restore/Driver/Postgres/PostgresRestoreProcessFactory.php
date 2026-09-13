<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Postgres;

use RuntimeException;
use Vortos\Backup\Service\Process\PgPassFile;
use Vortos\Backup\Service\Process\ProcessGuard;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\StreamMode;

final class PostgresRestoreProcessFactory
{
    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    /**
     * @return array{0: resource, 1: ProcessGuard} stdin pipe + guard
     */
    public function pgRestore(string $host, int $port, string $user, string $password, string $dbname): array
    {
        if ($this->launcher->which('pg_restore') === null) {
            throw new RuntimeException('Required binary "pg_restore" not found in PATH.');
        }

        $process = $this->launcher->start(
            new ProcessSpec(
                [
                    'pg_restore',
                    '--clean',
                    '--if-exists',
                    '--no-owner',
                    '--no-privileges',
                    '--no-password',
                    '--host=' . $host,
                    '--port=' . (string) $port,
                    '--username=' . $user,
                    '--dbname=' . $dbname,
                ],
                EnvironmentPolicy::Minimal,
                null,
                env: ['PGPASSFILE' => PgPassFile::for($password)],
            ),
            StreamMode::Pipe,
            StreamMode::Null,
            StreamMode::Capture,
        );

        return [$process->stdin(), new ProcessGuard($process, 'postgres_restore')];
    }
}
