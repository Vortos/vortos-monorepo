<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Mongo;

use RuntimeException;
use Vortos\Backup\Service\Process\MongoToolConfig;
use Vortos\Backup\Service\Process\ProcessGuard;
use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Process\StreamMode;
use Vortos\Foundation\Secret\SecretValue;

final class MongoRestoreProcessFactory
{
    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    /**
     * @return array{0: resource, 1: ProcessGuard} stdin pipe + guard
     */
    public function mongorestore(SecretValue $uri, string $database): array
    {
        if ($this->launcher->which('mongorestore') === null) {
            throw new RuntimeException('Required binary "mongorestore" not found in PATH.');
        }

        $process = $this->launcher->start(
            new ProcessSpec(
                ['mongorestore', MongoToolConfig::forUri($uri), '--db=' . $database, '--drop', '--archive', '--gzip'],
                EnvironmentPolicy::Minimal,
                null,
            ),
            StreamMode::Pipe,
            StreamMode::Null,
            StreamMode::Capture,
        );

        return [$process->stdin(), new ProcessGuard($process, 'mongorestore')];
    }
}
