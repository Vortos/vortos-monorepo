<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Explain;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Explain\FlagOverrideService;
use Vortos\FeatureFlags\Explain\OverrideAwareFlagRegistry;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRegistry;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

final class OverrideAwareFlagRegistryTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long!';

    public function test_no_override_delegates_to_inner(): void
    {
        $inner    = $this->createMock(FlagRegistryInterface::class);
        $inner->method('isEnabled')->willReturn(true);

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $registry = new OverrideAwareFlagRegistry($inner, $override);

        $this->assertTrue($registry->isEnabled('my-flag'));
    }

    public function test_boolean_override_forces_flag_on(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->method('isEnabled')->willReturn(false);

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $token    = $override->createToken('my-flag', true);
        $override->applyFromToken($token, 'development');

        $registry = new OverrideAwareFlagRegistry($inner, $override);

        $this->assertTrue($registry->isEnabled('my-flag'));
    }

    public function test_boolean_override_forces_flag_off(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->method('isEnabled')->willReturn(true);

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $token    = $override->createToken('my-flag', false);
        $override->applyFromToken($token, 'development');

        $registry = new OverrideAwareFlagRegistry($inner, $override);

        $this->assertFalse($registry->isEnabled('my-flag'));
    }

    public function test_string_override_forces_variant(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->method('variant')->willReturn('control');

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $token    = $override->createToken('my-flag', 'variant-b');
        $override->applyFromToken($token, 'development');

        $registry = new OverrideAwareFlagRegistry($inner, $override);

        $this->assertSame('variant-b', $registry->variant('my-flag'));
    }

    public function test_all_for_context_injects_override_flag_into_flags_list(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->method('allForContext')->willReturn([
            'flags'    => ['existing-flag'],
            'variants' => [],
            'payloads' => [],
            'version'  => 'v1:abc',
        ]);

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $token    = $override->createToken('new-flag', true);
        $override->applyFromToken($token, 'development');

        $registry = new OverrideAwareFlagRegistry($inner, $override);
        $result   = $registry->allForContext();

        $this->assertContains('new-flag', $result['flags']);
        $this->assertContains('existing-flag', $result['flags']);
    }

    public function test_all_for_context_removes_overridden_off_flag(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->method('allForContext')->willReturn([
            'flags'    => ['my-flag', 'other-flag'],
            'variants' => ['my-flag' => 'v1'],
            'payloads' => ['my-flag' => ['k' => 'v']],
            'version'  => 'v1:abc',
        ]);

        $override = new FlagOverrideService(enabled: true, secret: self::SECRET);
        $token    = $override->createToken('my-flag', false);
        $override->applyFromToken($token, 'development');

        $registry = new OverrideAwareFlagRegistry($inner, $override);
        $result   = $registry->allForContext();

        $this->assertNotContains('my-flag', $result['flags']);
        $this->assertContains('other-flag', $result['flags']);
        $this->assertArrayNotHasKey('my-flag', $result['variants']);
        $this->assertArrayNotHasKey('my-flag', $result['payloads']);
    }

    public function test_disabled_override_service_has_no_effect(): void
    {
        $inner = $this->createMock(FlagRegistryInterface::class);
        $inner->expects($this->once())->method('isEnabled')->willReturn(true);

        $override = new FlagOverrideService(enabled: false, secret: self::SECRET);
        $registry = new OverrideAwareFlagRegistry($inner, $override);

        $this->assertTrue($registry->isEnabled('my-flag'));
    }
}
