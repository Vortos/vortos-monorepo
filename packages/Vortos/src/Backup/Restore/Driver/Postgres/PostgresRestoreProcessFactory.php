<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Postgres;

use RuntimeException;
use Vortos\Backup\Service\Process\ProcessGuard;

final class PostgresRestoreProcessFactory
{
    /**
     * @return array{0: resource, 1: ProcessGuard} stdin pipe + guard
     */
    public function pgRestore(string $host, int $port, string $user, string $password, string $dbname): array
    {
        $this->assertBinary('pg_restore');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = ['PGPASSWORD' => $password, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin'];

        $command = [
            'pg_restore',
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--host=' . $host,
            '--port=' . (string) $port,
            '--username=' . $user,
            '--dbname=' . $dbname,
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn pg_restore process.');
        }

        fclose($pipes[1]);

        return [$pipes[0], new ProcessGuard($process, $pipes[2], 'postgres_restore')];
    }

    private function assertBinary(string $binary): void
    {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($found === null || trim((string) $found) === '') {
            throw new RuntimeException(sprintf('Required binary "%s" not found in PATH.', $binary));
        }
    }
}
