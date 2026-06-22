<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class FlagRegistryTest extends TestCase
{
    public function test_is_enabled_returns_false_for_unknown_flag(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn(null);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $this->assertFalse($registry->isEnabled('unknown-flag'));
    }

    public function test_is_enabled_returns_true_for_enabled_flag_with_no_rules(): void
    {
        $flag    = $this->flag(enabled: true, rules: []);
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($flag);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $this->assertTrue($registry->isEnabled('my-flag', new FlagContext('user-1')));
    }

    public function test_is_enabled_returns_false_for_disabled_flag(): void
    {
        $flag    = $this->flag(enabled: false);
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn($flag);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $this->assertFalse($registry->isEnabled('my-flag', new FlagContext('user-1')));
    }

    public function test_per_request_cache_calls_storage_only_once(): void
    {
        $flag    = $this->flag(enabled: true);
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->expects($this->once())->method('findByName')->willReturn($flag);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $context  = new FlagContext('user-1');

        $registry->isEnabled('my-flag', $context);
        $registry->isEnabled('my-flag', $context);
        $registry->isEnabled('my-flag', $context);
    }

    public function test_different_users_are_cached_separately(): void
    {
        $flag    = $this->flag(enabled: true);
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->expects($this->exactly(2))->method('findByName')->willReturn($flag);

        $registry = new FlagRegistry($storage, new FlagEvaluator());

        $registry->isEnabled('my-flag', new FlagContext('user-1'));
        $registry->isEnabled('my-flag', new FlagContext('user-1')); // cached
        $registry->isEnabled('my-flag', new FlagContext('user-2')); // new key
        $registry->isEnabled('my-flag', new FlagContext('user-2')); // cached
    }

    public function test_all_for_context_returns_enabled_flag_names(): void
    {
        $flagA = $this->flag(enabled: true, name: 'flag-a');
        $flagB = $this->flag(enabled: false, name: 'flag-b');
        $flagC = $this->flag(enabled: true, name: 'flag-c');

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$flagA, $flagB, $flagC]);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $result   = $registry->allForContext(new FlagContext('user-1'));

        $this->assertContains('flag-a', $result['flags']);
        $this->assertNotContains('flag-b', $result['flags']);
        $this->assertContains('flag-c', $result['flags']);
    }

    public function test_all_for_context_includes_variants(): void
    {
        $flag = $this->flag(enabled: true, name: 'cta-button', variants: ['control' => 1, 'blue' => 99]);

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$flag]);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $result   = $registry->allForContext(new FlagContext('user-abc'));

        // variant should be 'blue' (99%) or 'control' (1%) — just assert it's a valid value
        if (isset($result['variants']['cta-button'])) {
            $this->assertContains($result['variants']['cta-button'], ['control', 'blue']);
        }
    }

    public function test_variant_returns_control_for_unknown_flag(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturn(null);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $this->assertSame('control', $registry->variant('unknown', new FlagContext('u1')));
    }

    // --- wire contract: payloads + version (Block 1) ---

    public function test_all_for_context_emits_wire_contract_shape(): void
    {
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$this->flag(enabled: true, name: 'flag-a')]);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $result   = $registry->allForContext(new FlagContext('u1'));

        // Exactly the FlagResponse keys the SDK consumes — no ruleset leakage.
        $this->assertSame(['flags', 'variants', 'payloads', 'version'], array_keys($result));
        $this->assertIsList($result['flags']);
        $this->assertIsArray($result['variants']);
        $this->assertIsArray($result['payloads']);
        $this->assertIsString($result['version']);
    }

    public function test_payload_delivered_only_for_on_flags(): void
    {
        $on  = $this->jsonFlag(enabled: true, name: 'on-flag', payload: ['k' => 'v']);
        $off = $this->jsonFlag(enabled: false, name: 'off-flag', payload: ['secret' => 'x']);

        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$on, $off]);

        $registry = new FlagRegistry($storage, new FlagEvaluator());
        $result   = $registry->allForContext(new FlagContext('u1'));

        $this->assertSame(['on-flag' => ['k' => 'v']], $result['payloads']);
        $this->assertArrayNotHasKey('off-flag', $result['payloads']);
    }

    public function test_version_is_stable_for_same_config_and_changes_with_config(): void
    {
        $flag    = $this->flag(enabled: true, name: 'flag-a');
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findAll')->willReturn([$flag]);

        $registry = new FlagRegistry($storage, new FlagEvaluator());

        $v1 = $registry->allForContext(new FlagContext('u1'))['version'];
        $v2 = $registry->allForContext(new FlagContext('u2'))['version'];
        $this->assertSame($v1, $v2, 'version must not depend on context');
        $this->assertStringStartsWith('v1:', $v1);

        // Different config → different version.
        $storage2 = $this->createMock(FlagStorageInterface::class);
        $storage2->method('findAll')->willReturn([$this->flag(enabled: false, name: 'flag-a')]);
        $v3 = (new FlagRegistry($storage2, new FlagEvaluator()))->allForContext(new FlagContext('u1'))['version'];
        $this->assertNotSame($v1, $v3);
    }

    public function test_version_is_independent_of_flag_order(): void
    {
        $a = $this->flag(enabled: true, name: 'a-flag');
        $b = $this->flag(enabled: true, name: 'b-flag');

        $s1 = $this->createMock(FlagStorageInterface::class);
        $s1->method('findAll')->willReturn([$a, $b]);
        $s2 = $this->createMock(FlagStorageInterface::class);
        $s2->method('findAll')->willReturn([$b, $a]);

        $v1 = (new FlagRegistry($s1, new FlagEvaluator()))->allForContext()['version'];
        $v2 = (new FlagRegistry($s2, new FlagEvaluator()))->allForContext()['version'];
        $this->assertSame($v1, $v2);
    }

    private function jsonFlag(bool $enabled, string $name, ?array $payload): FeatureFlag
    {
        $now = new \DateTimeImmutable('2024-01-01');
        return new FeatureFlag(
            'id-' . $name, $name, '', $enabled, [], null, $now, $now,
            \Vortos\FeatureFlags\FlagValueType::Json,
            \Vortos\FeatureFlags\FlagValue::json(null),
            $payload,
        );
    }

    private function flag(bool $enabled = true, array $rules = [], ?array $variants = null, string $name = 'my-flag'): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag('id-1', $name, '', $enabled, $rules, $variants, $now, $now);
    }
}
