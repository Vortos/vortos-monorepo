<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Release\Schema\SchemaFingerprint;

final class CurrentDeployStateTest extends TestCase
{
    public function test_first_deploy_has_no_pending_contracts(): void
    {
        $state = CurrentDeployState::firstDeploy();

        self::assertFalse($state->pendingContract());
        self::assertSame([], $state->pendingContractMigrations);
    }

    public function test_pending_contract_true_when_migrations_present(): void
    {
        $state = new CurrentDeployState(
            ActiveColor::Blue,
            'sha256:abc',
            new SchemaFingerprint(['m001']),
            ['m_drop_x'],
        );

        self::assertTrue($state->pendingContract());
        self::assertSame(['m_drop_x'], $state->pendingContractMigrations);
    }

    public function test_pending_contract_false_when_empty(): void
    {
        $state = new CurrentDeployState(
            ActiveColor::Blue,
            'sha256:abc',
            new SchemaFingerprint(['m001']),
            [],
        );

        self::assertFalse($state->pendingContract());
    }

    public function test_default_constructor_has_empty_contracts(): void
    {
        $state = new CurrentDeployState(
            ActiveColor::Blue,
            'sha256:abc',
            new SchemaFingerprint(['m001']),
        );

        self::assertFalse($state->pendingContract());
        self::assertSame([], $state->pendingContractMigrations);
    }

    public function test_multiple_pending_contracts(): void
    {
        $state = new CurrentDeployState(
            ActiveColor::Green,
            'sha256:abc',
            new SchemaFingerprint(['m001']),
            ['m_drop_a', 'm_drop_b', 'm_drop_c'],
        );

        self::assertTrue($state->pendingContract());
        self::assertCount(3, $state->pendingContractMigrations);
    }
}
