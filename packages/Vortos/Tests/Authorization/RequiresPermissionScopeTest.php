<?php
declare(strict_types=1);

namespace Vortos\Tests\Authorization;

use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Attribute\RequiresPermission;
use Vortos\Authorization\Scope\Contract\ScopeMode;

enum PermissionScopeTestEnum: string { case DocumentsEdit = 'documents.edit.own'; }

final class RequiresPermissionScopeTest extends TestCase
{
    public function test_accepts_string_permission(): void
    {
        $attr = new RequiresPermission('documents.edit.own');
        $this->assertSame('documents.edit.own', $attr->permission);
    }

    public function test_accepts_backed_enum_permission(): void
    {
        $attr = new RequiresPermission(PermissionScopeTestEnum::DocumentsEdit);
        $this->assertSame('documents.edit.own', $attr->permission);
    }

    public function test_no_scope_by_default(): void
    {
        $attr = new RequiresPermission('documents.edit.own');
        $this->assertNull($attr->scope);
    }

    public function test_single_scope_string(): void
    {
        $attr = new RequiresPermission('documents.edit.own', scope: 'org');
        $this->assertSame('org', $attr->scope);
    }

    public function test_multiple_scopes_array(): void
    {
        $attr = new RequiresPermission('documents.edit.own', scope: ['org', 'team']);
        $this->assertSame(['org', 'team'], $attr->scope);
    }

    public function test_default_scope_mode_is_all(): void
    {
        $attr = new RequiresPermission('documents.edit.own', scope: 'org');
        $this->assertSame(ScopeMode::All, $attr->scopeMode);
    }

    public function test_scope_mode_any(): void
    {
        $attr = new RequiresPermission('documents.edit.own', scope: ['org', 'team'], scopeMode: ScopeMode::Any);
        $this->assertSame(ScopeMode::Any, $attr->scopeMode);
    }

    public function test_is_repeatable(): void
    {
        $reflection = new \ReflectionClass(RequiresPermission::class);
        $flags = $reflection->getAttributes(\Attribute::class)[0]->newInstance()->flags;
        $this->assertTrue((bool)($flags & \Attribute::IS_REPEATABLE));
    }
}
