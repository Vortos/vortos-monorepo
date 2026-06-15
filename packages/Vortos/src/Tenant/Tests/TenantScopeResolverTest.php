<?php

declare(strict_types=1);

namespace Vortos\Tenant\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Tenant\Attribute\TenantScoped;
use Vortos\Tenant\TenantScopeResolver;

#[TenantScoped]
class DefaultScopedFixture {}

#[TenantScoped(column: 'org_id')]
class CustomColumnFixture {}

class UnscopedFixture {}

final class TenantScopeResolverTest extends TestCase
{
    public function test_default_column_for_scoped_class(): void
    {
        $this->assertSame('tenant_id', TenantScopeResolver::columnFor(DefaultScopedFixture::class));
        $this->assertTrue(TenantScopeResolver::isScoped(DefaultScopedFixture::class));
    }

    public function test_custom_column(): void
    {
        $this->assertSame('org_id', TenantScopeResolver::columnFor(CustomColumnFixture::class));
    }

    public function test_unscoped_class_returns_null(): void
    {
        $this->assertNull(TenantScopeResolver::columnFor(UnscopedFixture::class));
        $this->assertFalse(TenantScopeResolver::isScoped(UnscopedFixture::class));
    }

    public function test_accepts_an_instance(): void
    {
        $this->assertSame('tenant_id', TenantScopeResolver::columnFor(new DefaultScopedFixture()));
    }

    public function test_empty_column_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TenantScoped(column: '');
    }
}
