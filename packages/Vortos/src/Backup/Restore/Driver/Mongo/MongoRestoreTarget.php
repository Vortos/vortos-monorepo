<?php

declare(strict_types=1);

namespace Vortos\Backup\Restore\Driver\Mongo;

use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('mongo')]
final class MongoRestoreTarget implements RestoreTargetInterface
{
    public function __construct(
        private readonly MongoRestoreProcessFactory $processes,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RestoreTargetCapability::StreamingRestore->value => true,
            RestoreTargetCapability::CleanRestore->value => true,
            RestoreTargetCapability::PointInTime->value => false,
        ]);
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Mongo;
    }

    public function restore(iterable $chunks, RestoreRequest $request): void
    {
        $parsed = parse_url($request->destinationDsn);
        if ($parsed === false) {
            throw new RuntimeException(sprintf('Invalid Mongo DSN: %s', $request->destinationDsn));
        }
        $database = ltrim($parsed['path'] ?? '/test', '/');

        [$stdin, $guard] = $this->processes->mongorestore($request->destinationDsn, $database);

        try {
            foreach ($chunks as $chunk) {
                $written = fwrite($stdin, $chunk);
                if ($written === false) {
                    throw new RuntimeException('Failed to write to mongorestore stdin.');
                }
            }
        } finally {
            fclose($stdin);
        }

        $guard->assertSuccess();
    }
}
