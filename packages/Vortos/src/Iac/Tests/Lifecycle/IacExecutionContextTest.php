<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacExecutionContext;

final class IacExecutionContextTest extends TestCase
{
    public function test_defaults(): void
    {
        $ctx = new IacExecutionContext();
        $this->assertSame(10, $ctx->parallelism);
        $this->assertSame(600, $ctx->timeoutSeconds);
        $this->assertSame([], $ctx->envAllowlist);
        $this->assertSame([], $ctx->providerCredentials);
        $this->assertNull($ctx->binaryHint);
        $this->assertSame(60, $ctx->lockTimeoutSeconds);
        $this->assertFalse($ctx->allowDestructive);
    }

    public function test_with_allow_destructive_returns_new_instance(): void
    {
        $ctx = new IacExecutionContext();
        $new = $ctx->withAllowDestructive(true);

        $this->assertFalse($ctx->allowDestructive);
        $this->assertTrue($new->allowDestructive);
        $this->assertSame($ctx->parallelism, $new->parallelism);
        $this->assertSame($ctx->timeoutSeconds, $new->timeoutSeconds);
    }

    public function test_with_allow_destructive_false(): void
    {
        $ctx = new IacExecutionContext(allowDestructive: true);
        $new = $ctx->withAllowDestructive(false);
        $this->assertFalse($new->allowDestructive);
    }

    public function test_custom_values(): void
    {
        $ctx = new IacExecutionContext(
            parallelism: 5,
            timeoutSeconds: 120,
            envAllowlist: ['HOME'],
            binaryHint: '/usr/local/bin/tofu',
            lockTimeoutSeconds: 30,
            allowDestructive: true,
        );

        $this->assertSame(5, $ctx->parallelism);
        $this->assertSame(120, $ctx->timeoutSeconds);
        $this->assertSame(['HOME'], $ctx->envAllowlist);
        $this->assertSame('/usr/local/bin/tofu', $ctx->binaryHint);
        $this->assertSame(30, $ctx->lockTimeoutSeconds);
        $this->assertTrue($ctx->allowDestructive);
    }

    public function test_invalid_parallelism_zero_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacExecutionContext(parallelism: 0);
    }

    public function test_invalid_parallelism_negative_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacExecutionContext(parallelism: -1);
    }

    public function test_invalid_timeout_zero_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacExecutionContext(timeoutSeconds: 0);
    }

    public function test_invalid_timeout_negative_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacExecutionContext(timeoutSeconds: -5);
    }

    public function test_invalid_lock_timeout_negative_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new IacExecutionContext(lockTimeoutSeconds: -1);
    }

    public function test_lock_timeout_zero_is_valid(): void
    {
        $ctx = new IacExecutionContext(lockTimeoutSeconds: 0);
        $this->assertSame(0, $ctx->lockTimeoutSeconds);
    }
}
