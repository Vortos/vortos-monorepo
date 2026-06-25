<?php

declare(strict_types=1);

namespace Vortos\Backup\Testing;

use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Restore\Capability\RestoreTargetCapability;
use Vortos\Backup\Restore\RestoreTargetInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * TCK for {@see RestoreTargetInterface} drivers. A concrete test supplies
 * {@see createDriver()} and {@see expectedEngine()}.
 */
abstract class RestoreTargetConformanceTestCase extends ConformanceTestCase
{
    abstract protected function expectedEngine(): DatabaseEngine;

    final protected function restoreTarget(): RestoreTargetInterface
    {
        $driver = $this->createDriver();
        self::assertInstanceOf(RestoreTargetInterface::class, $driver);

        return $driver;
    }

    final public function test_engine_matches(): void
    {
        self::assertSame($this->expectedEngine(), $this->restoreTarget()->engine());
    }

    final public function test_streaming_restore_capability_declared(): void
    {
        self::assertTrue(
            $this->restoreTarget()->capabilities()->supports(RestoreTargetCapability::StreamingRestore),
            'A restore target must support streaming restore.',
        );
    }
}
