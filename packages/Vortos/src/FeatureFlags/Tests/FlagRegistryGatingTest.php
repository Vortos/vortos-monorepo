<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\Resolution\EffectiveFlagResolverInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class FlagRegistryGatingTest extends TestCase
{
    public function test_authz_gate_can_turn_a_flag_off(): void
    {
        $registry = $this->registry($this->flag('cap', enabled: true, scope: 'billing.read.any'), gateAllows: false);

        $this->assertFalse($registry->isEnabled('cap', new FlagContext('u1')));
    }

    public function test_authz_gate_pass_lets_an_enabled_flag_through(): void
    {
        $registry = $this->registry($this->flag('cap', enabled: true, scope: 'billing.read.any'), gateAllows: true);

        $this->assertTrue($registry->isEnabled('cap', new FlagContext('u1')));
    }

    public function test_authz_gate_can_never_turn_a_flag_on(): void
    {
        // Flag is OFF in config; an allowing gate must NOT be able to enable it (deny-only).
        $registry = $this->registry($this->flag('cap', enabled: false, scope: 'billing.read.any'), gateAllows: true);

        $this->assertFalse($registry->isEnabled('cap', new FlagContext('u1')));
    }

    public function test_gated_flag_yields_control_variant_when_denied(): void
    {
        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            'id', 'exp', '', true, [], ['control' => 50, 'treatment' => 50], $now, $now,
            requiredScope: 'billing.read.any',
        );

        $registry = $this->registry($flag, gateAllows: false);

        $this->assertSame('control', $registry->variant('exp', new FlagContext('u1')));
    }

    public function test_resolver_override_drives_is_enabled(): void
    {
        // Resolver returns a DISABLED override even though storage's global is enabled.
        $now      = new \DateTimeImmutable();
        $override = new FeatureFlag('id', 'x', '', false, [], null, $now, $now);

        $resolver = new class($override) implements EffectiveFlagResolverInterface {
            public function __construct(private FeatureFlag $override) {}
            public function resolve(string $name, FlagContext $context): ?FeatureFlag
            {
                return $this->override;
            }
            public function resolveAll(FlagContext $context): array
            {
                return [$this->override];
            }
        };

        $storage  = $this->createMock(FlagStorageInterface::class);
        $registry = new FlagRegistry($storage, new FlagEvaluator(), $resolver, null);

        $this->assertFalse($registry->isEnabled('x', new FlagContext('u1')));
    }

    private function registry(FeatureFlag $flag, bool $gateAllows): FlagRegistry
    {
        $resolver = new class($flag) implements EffectiveFlagResolverInterface {
            public function __construct(private FeatureFlag $flag) {}
            public function resolve(string $name, FlagContext $context): ?FeatureFlag
            {
                return $name === $this->flag->name ? $this->flag : null;
            }
            public function resolveAll(FlagContext $context): array
            {
                return [$this->flag];
            }
        };

        $gate = new class($gateAllows) implements FlagAuthzGateInterface {
            public function __construct(private bool $allows) {}
            public function allows(FeatureFlag $flag, FlagContext $context): bool
            {
                return $this->allows;
            }
        };

        return new FlagRegistry($this->createMock(FlagStorageInterface::class), new FlagEvaluator(), $resolver, $gate);
    }

    private function flag(string $name, bool $enabled, ?string $scope): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag('id-' . $name, $name, '', $enabled, [], null, $now, $now, requiredScope: $scope);
    }
}
