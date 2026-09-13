<?php

declare(strict_types=1);

namespace Vortos\Backup\Driver\Mongo;

use Vortos\Backup\Domain\Exception\DumpFailedException;
use Vortos\Backup\Service\Process\MongoToolConfig;
use Vortos\Backup\Service\Process\ProcessGuard;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\StreamMode;
use Vortos\Foundation\Secret\SecretValue;

/**
 * Spawns `mongodump --archive --gzip`, streaming the archive to stdout.
 *
 * The connection URI embeds credentials, so it reaches mongodump through `--config=<0600 file>` — the
 * mechanism the MongoDB tools document for exactly this — and never through `--uri=` on argv, where any
 * local user could read it from /proc/<pid>/cmdline.
 */
final class MongoProcessFactory
{
    public function __construct(
        private readonly SecretValue $uri,
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    /**
     * @return array{0: resource, 1: ProcessGuard}
     */
    public function mongodump(bool $consistentSnapshot): array
    {
        if ($this->launcher->which('mongodump') === null) {
            throw DumpFailedException::missingBinary('mongo', 'mongodump');
        }

        $argv = ['mongodump', MongoToolConfig::forUri($this->uri), '--archive', '--gzip'];
        if ($consistentSnapshot) {
            $argv[] = '--oplog';
        }

        $process = $this->launcher->start(
            new ProcessSpec($argv, EnvironmentPolicy::Minimal, null),
            StreamMode::Null,
            StreamMode::Pipe,
            StreamMode::Capture,
        );

        return [$process->stdout(), new ProcessGuard($process, 'mongo')];
    }
}
