<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreRequest;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/** @internal */
final class FakeRestoreTarget implements RestoreTargetInterface
{
    public ?string $restoredData = null;
    public bool $throwOnRestore = false;

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            RestoreTargetCapability::StreamingRestore->value => true,
            RestoreTargetCapability::CleanRestore->value => true,
        ]);
    }

    public function engine(): DatabaseEngine
    {
        return DatabaseEngine::Postgres;
    }

    public function restore(iterable $chunks, RestoreRequest $request): void
    {
        if ($this->throwOnRestore) {
            throw new \RuntimeException('Restore exploded.');
        }

        $buf = '';
        foreach ($chunks as $chunk) {
            $buf .= $chunk;
        }
        $this->restoredData = $buf;
    }
}
