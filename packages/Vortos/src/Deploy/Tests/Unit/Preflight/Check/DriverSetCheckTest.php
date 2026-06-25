<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\DriverSetCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class DriverSetCheckTest extends TestCase
{
    use PreflightTestFactory;

    private function check(?DeployStrategyRegistry $strategies = null): DriverSetCheck
    {
        return new DriverSetCheck(
            $this->targetRegistry(),
            $this->registryRegistry(),
            $this->credentialRegistry(),
            $strategies ?? $this->strategyRegistry(),
        );
    }

    public function test_all_registered_passes(): void
    {
        $finding = $this->check()->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
        $this->assertSame('driver_set.registered', $finding->id);
    }

    public function test_missing_host_fails_and_names_registered_keys(): void
    {
        $ctx = $this->context($this->definition(host: 'ghost'));

        $finding = $this->check()->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('ghost', $finding->detail);
        $this->assertStringContainsString('fake-target', $finding->detail);
    }

    public function test_missing_registry_fails(): void
    {
        $finding = $this->check()->check($this->context($this->definition(registry: 'nope')));

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('registry', $finding->detail);
    }

    public function test_missing_credential_fails(): void
    {
        $finding = $this->check()->check($this->context($this->definition(credential: 'nope')));

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('credential', $finding->detail);
    }

    public function test_missing_strategy_fails(): void
    {
        // A registry that knows no strategies → the selected blue-green is "unregistered".
        $finding = $this->check(new DeployStrategyRegistry())->check($this->context());

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('strategy', $finding->detail);
    }

    public function test_unknown_strategy_selection_fails(): void
    {
        $ctx = $this->context($this->definition(strategy: DeployStrategy::Canary));
        $registry = new DeployStrategyRegistry(); // canary not registered

        $finding = $this->check($registry)->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
    }
}
