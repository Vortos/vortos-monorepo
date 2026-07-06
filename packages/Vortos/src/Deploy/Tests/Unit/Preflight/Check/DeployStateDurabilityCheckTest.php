<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\DeployStateDurabilityCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class DeployStateDurabilityCheckTest extends TestCase
{
    use PreflightTestFactory;

    public function test_redis_configured_passes(): void
    {
        $check = new DeployStateDurabilityCheck(
            stateStoreKind: 'redis',
            pushDelivery: true,
            hasRemoteHost: true,
            redisConfigured: true,
        );

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    public function test_redis_selected_without_connection_fails(): void
    {
        $check = new DeployStateDurabilityCheck(
            stateStoreKind: 'redis',
            pushDelivery: true,
            hasRemoteHost: true,
            redisConfigured: false,
        );

        $finding = $check->check($this->context());
        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('no Redis connection', $finding->summary);
    }

    public function test_mongo_passes(): void
    {
        $check = new DeployStateDurabilityCheck('mongo', true, true, false);

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    public function test_file_store_in_push_one_shot_fails(): void
    {
        $check = new DeployStateDurabilityCheck(
            stateStoreKind: 'file',
            pushDelivery: true,
            hasRemoteHost: true,
            redisConfigured: false,
        );

        $finding = $check->check($this->context());
        self::assertSame(PreflightStatus::Fail, $finding->status);
        self::assertStringContainsString('ephemeral', $finding->summary);
        self::assertStringContainsString('DEPLOY_STATE_STORE=redis', $finding->remediation);
    }

    public function test_file_store_single_node_passes(): void
    {
        // No push host ⇒ a persistent local var/deploy-state is fine.
        $check = new DeployStateDurabilityCheck('file', false, false, false);

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    public function test_file_store_push_without_host_passes(): void
    {
        $check = new DeployStateDurabilityCheck('file', true, false, false);

        self::assertSame(PreflightStatus::Pass, $check->check($this->context())->status);
    }

    public function test_id_and_category_are_stable(): void
    {
        $check = new DeployStateDurabilityCheck('redis', true, true, true);

        self::assertSame('state.durable_store', $check->id());
    }
}
