<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Strategy;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\CanaryStrategy;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Strategy\RecreateStrategy;
use Vortos\Deploy\Strategy\RollingStrategy;

final class StrategyRegistryTest extends TestCase
{
    public function test_register_and_get(): void
    {
        $registry = new DeployStrategyRegistry();
        $strategy = new BlueGreenStrategy();
        $registry->register($strategy);

        self::assertSame($strategy, $registry->get(DeployStrategy::BlueGreen));
    }

    public function test_get_unknown_throws(): void
    {
        $registry = new DeployStrategyRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown deploy strategy "rolling"');

        $registry->get(DeployStrategy::Rolling);
    }

    public function test_has(): void
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new BlueGreenStrategy());

        self::assertTrue($registry->has(DeployStrategy::BlueGreen));
        self::assertFalse($registry->has(DeployStrategy::Rolling));
    }

    public function test_keys_sorted(): void
    {
        $registry = new DeployStrategyRegistry();
        $registry->register(new RollingStrategy());
        $registry->register(new BlueGreenStrategy());
        $registry->register(new CanaryStrategy());
        $registry->register(new RecreateStrategy());

        $keys = $registry->keys();
        $sorted = $keys;
        sort($sorted);
        self::assertSame($sorted, $keys);
    }

    public function test_each_strategy_has_correct_key(): void
    {
        self::assertSame(DeployStrategy::BlueGreen, (new BlueGreenStrategy())->key());
        self::assertSame(DeployStrategy::Rolling, (new RollingStrategy())->key());
        self::assertSame(DeployStrategy::Recreate, (new RecreateStrategy())->key());
        self::assertSame(DeployStrategy::Canary, (new CanaryStrategy())->key());
    }

    public function test_blue_green_requires_blue_green_and_health_gate(): void
    {
        $req = (new BlueGreenStrategy())->requires();
        self::assertContains('blue_green', $req->capabilities);
        self::assertContains('health_gate', $req->capabilities);
    }

    public function test_rolling_requires_rolling_across_nodes(): void
    {
        $req = (new RollingStrategy())->requires();
        self::assertContains('rolling_across_nodes', $req->capabilities);
    }

    public function test_recreate_requires_accepts_downtime(): void
    {
        $req = (new RecreateStrategy())->requires();
        self::assertContains('accepts_downtime', $req->capabilities);
    }

    public function test_canary_requires_canary_and_blue_green(): void
    {
        $req = (new CanaryStrategy())->requires();
        self::assertContains('canary', $req->capabilities);
        self::assertContains('blue_green', $req->capabilities);
    }
}
