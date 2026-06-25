<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Mongo;

use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Service\Process\ProcessGuard;

/**
 * Builds and spawns `mongodump --archive --gzip`, streaming the archive to stdout.
 *
 * The connection URI (which may embed credentials) is passed via the `--uri` argument
 * sourced from configuration. It is never logged; stderr is captured by the guard for
 * diagnostics only on failure.
 */
final class MongoProcessFactory
{
    public function __construct(private readonly string $uri)
    {
    }

    /**
     * @return array{0: resource, 1: ProcessGuard}
     */
    public function mongodump(bool $consistentSnapshot): array
    {
        $this->assertBinary('mongodump');

        $command = [
            'mongodump',
            '--uri=' . $this->uri,
            '--archive',
            '--gzip',
        ];
        if ($consistentSnapshot) {
            $command[] = '--oplog';
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            throw DumpFailedException::reason('Failed to spawn mongodump process.');
        }

        return [$pipes[1], new ProcessGuard($process, $pipes[2], 'mongo')];
    }

    private function assertBinary(string $binary): void
    {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($found === null || trim((string) $found) === '') {
            throw DumpFailedException::missingBinary('mongo', $binary);
        }
    }
}
