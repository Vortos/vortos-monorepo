<?php

declare(strict_types=1);

namespace Vortos\Tenant\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Tenant\Exception\MissingTenantContextException;
use Vortos\Tenant\TenantContext;

final class TenantContextTest extends TestCase
{
    public function test_starts_empty(): void
    {
        $ctx = new TenantContext();

        $this->assertNull($ctx->tenantId());
        $this->assertFalse($ctx->hasTenant());
        $this->assertFalse($ctx->isSystem());
        $this->assertNull($ctx->scopingDecision());
    }

    public function test_set_and_read_tenant(): void
    {
        $ctx = new TenantContext();
        $ctx->set('acme');

        $this->assertSame('acme', $ctx->tenantId());
        $this->assertTrue($ctx->hasTenant());
        $this->assertSame('acme', $ctx->requireTenantId());
        $this->assertSame('acme', $ctx->scopingDecision());
    }

    public function test_set_rejects_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TenantContext())->set('');
    }

    public function test_require_throws_when_empty(): void
    {
        $this->expectException(MissingTenantContextException::class);
        (new TenantContext())->requireTenantId();
    }

    public function test_reset_clears_state(): void
    {
        $ctx = new TenantContext();
        $ctx->set('acme');
        $ctx->reset();

        $this->assertFalse($ctx->hasTenant());
        $this->assertFalse($ctx->isSystem());
    }

    public function test_run_as_scopes_then_restores(): void
    {
        $ctx = new TenantContext();
        $ctx->set('outer');

        $seen = $ctx->runAs('inner', function () use ($ctx) {
            return $ctx->tenantId();
        });

        $this->assertSame('inner', $seen);
        $this->assertSame('outer', $ctx->tenantId(), 'previous tenant must be restored');
    }

    public function test_run_as_restores_even_on_exception(): void
    {
        $ctx = new TenantContext();
        $ctx->set('outer');

        try {
            $ctx->runAs('inner', function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('outer', $ctx->tenantId());
    }

    public function test_run_as_system_bypasses_scoping_then_restores(): void
    {
        $ctx = new TenantContext();
        $ctx->set('acme');

        $decision = $ctx->runAsSystem(function () use ($ctx) {
            $this->assertTrue($ctx->isSystem());
            return $ctx->scopingDecision();
        });

        $this->assertSame(TenantContext::SYSTEM, $decision);
        $this->assertSame('acme', $ctx->tenantId(), 'tenant restored after system scope');
        $this->assertFalse($ctx->isSystem());
    }
}
