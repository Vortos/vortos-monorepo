<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Authz\Management;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\FeatureFlags\Authz\Management\FlagManagementPermissionCatalog;

final class FlagManagementPermissionCatalogTest extends TestCase
{
    public function test_declares_all_five_management_permissions(): void
    {
        $meta = FlagManagementPermissionCatalog::meta();

        $this->assertArrayHasKey(FlagManagementPermissionCatalog::READ_ANY, $meta);
        $this->assertArrayHasKey(FlagManagementPermissionCatalog::WRITE_ANY, $meta);
        $this->assertArrayHasKey(FlagManagementPermissionCatalog::PUBLISH_ANY, $meta);
        $this->assertArrayHasKey(FlagManagementPermissionCatalog::APPROVE_ANY, $meta);
        $this->assertArrayHasKey(FlagManagementPermissionCatalog::ADMIN_ANY, $meta);
        $this->assertSame('approve.any', FlagManagementPermissionCatalog::APPROVE_ANY);
        $this->assertSame('admin.any', FlagManagementPermissionCatalog::ADMIN_ANY);
    }

    public function test_permission_values_match_the_gate_strings(): void
    {
        // These MUST equal what PolicyEngineManagementAuthzGate requires — the resource
        // prefix 'flags' comes from the #[PermissionCatalog(resource: 'flags')] attribute.
        $this->assertSame('read.any', FlagManagementPermissionCatalog::READ_ANY);
        $this->assertSame('write.any', FlagManagementPermissionCatalog::WRITE_ANY);
        $this->assertSame('publish.any', FlagManagementPermissionCatalog::PUBLISH_ANY);
    }

    public function test_is_declared_for_the_flags_resource(): void
    {
        $attributes = (new \ReflectionClass(FlagManagementPermissionCatalog::class))
            ->getAttributes(PermissionCatalog::class);

        $this->assertNotEmpty($attributes);
        $this->assertSame('flags', $attributes[0]->newInstance()->resource);
    }

    public function test_write_and_publish_are_marked_dangerous(): void
    {
        $meta = FlagManagementPermissionCatalog::meta();

        $this->assertTrue($meta[FlagManagementPermissionCatalog::WRITE_ANY]['dangerous']);
        $this->assertTrue($meta[FlagManagementPermissionCatalog::PUBLISH_ANY]['dangerous']);
        $this->assertFalse($meta[FlagManagementPermissionCatalog::READ_ANY]['dangerous']);
    }
}
