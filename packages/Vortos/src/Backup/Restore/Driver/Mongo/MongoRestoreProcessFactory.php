<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Mongo;

use RuntimeException;
use Vortos\Backup\Service\Process\ProcessGuard;

final class MongoRestoreProcessFactory
{
    /**
     * @return array{0: resource, 1: ProcessGuard} stdin pipe + guard
     */
    public function mongorestore(string $uri, string $database): array
    {
        $this->assertBinary('mongorestore');

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $command = [
            'mongorestore',
            '--uri=' . $uri,
            '--db=' . $database,
            '--drop',
            '--archive',
            '--gzip',
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn mongorestore process.');
        }

        fclose($pipes[1]);

        return [$pipes[0], new ProcessGuard($process, $pipes[2], 'mongorestore')];
    }

    private function assertBinary(string $binary): void
    {
        $found = @shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');
        if ($found === null || trim((string) $found) === '') {
            throw new RuntimeException(sprintf('Required binary "%s" not found in PATH.', $binary));
        }
    }
}
