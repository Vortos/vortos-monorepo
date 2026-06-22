<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FlagScopeContext;

final class FlagScopeContextTest extends TestCase
{
    public function test_default_environment_is_production(): void
    {
        $ctx = new FlagScopeContext();
        $this->assertSame(FlagScopeContext::ENV_PRODUCTION, $ctx->environment());
    }

    public function test_with_environment_changes_active_env(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('staging');
        $this->assertSame('staging', $ctx->environment());
    }

    public function test_with_environment_trims_whitespace(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('  dev  ');
        $this->assertSame('dev', $ctx->environment());
    }

    public function test_with_environment_rejects_blank(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FlagScopeContext())->withEnvironment('');
    }

    public function test_with_environment_rejects_whitespace_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FlagScopeContext())->withEnvironment('   ');
    }

    public function test_with_environment_rejects_names_over_64_chars(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FlagScopeContext())->withEnvironment(str_repeat('x', 65));
    }

    public function test_with_environment_accepts_exactly_64_chars(): void
    {
        $ctx = new FlagScopeContext();
        $name = str_repeat('a', 64);
        $ctx->withEnvironment($name);
        $this->assertSame($name, $ctx->environment());
    }

    public function test_run_as_restores_previous_env(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('staging');

        $seenEnv = null;
        $ctx->runAs('dev', function () use ($ctx, &$seenEnv): void {
            $seenEnv = $ctx->environment();
        });

        $this->assertSame('dev', $seenEnv);
        $this->assertSame('staging', $ctx->environment(), 'previous env restored');
    }

    public function test_run_as_restores_on_exception(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('production');

        try {
            $ctx->runAs('staging', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {}

        $this->assertSame('production', $ctx->environment(), 'env restored even after exception');
    }

    public function test_run_as_returns_callable_result(): void
    {
        $ctx    = new FlagScopeContext();
        $result = $ctx->runAs('dev', fn() => 42);
        $this->assertSame(42, $result);
    }

    public function test_nested_run_as_restores_each_level(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('production');

        $innerSeen = null;
        $ctx->runAs('staging', function () use ($ctx, &$innerSeen): void {
            $ctx->runAs('dev', function () use ($ctx, &$innerSeen): void {
                $innerSeen = $ctx->environment();
            });
            // After inner runAs returns we must be back in 'staging'.
            $this->assertSame('staging', $ctx->environment());
        });

        $this->assertSame('dev', $innerSeen);
        $this->assertSame('production', $ctx->environment());
    }

    public function test_reset_restores_default_production(): void
    {
        $ctx = new FlagScopeContext();
        $ctx->withEnvironment('dev');
        $ctx->reset();
        $this->assertSame(FlagScopeContext::ENV_PRODUCTION, $ctx->environment());
    }

    public function test_env_production_constant_value(): void
    {
        $this->assertSame('production', FlagScopeContext::ENV_PRODUCTION);
    }
}
