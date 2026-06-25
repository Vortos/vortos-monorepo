<?php

declare(strict_types=1);

namespace Vortos\Deploy\Testing;

use Vortos\Deploy\Contract\ContractReadinessInterface;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class ContractReadinessConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createReadiness(): ContractReadinessInterface;

    protected function createDriver(): ContractReadinessInterface
    {
        return $this->createReadiness();
    }

    final public function test_reason_returns_non_empty_string(): void
    {
        $readiness = $this->createReadiness();
        $reason = $readiness->reason('some_migration_id');

        $this->assertNotSame('', $reason, 'reason() must return a non-empty explanation.');
    }

    final public function test_negative_case_not_cleared_by_default(): void
    {
        $readiness = $this->createReadiness();
        $cleared = $readiness->isCleared('uncleared_migration', new EnvironmentName('staging'));

        $this->assertFalse(
            $cleared,
            'A contract readiness driver must honestly report "not cleared" rather than silently clearing.',
        );
    }
}
