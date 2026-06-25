<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;
use Vortos\Pipeline\Model\Permissions;

final class PermissionsTest extends TestCase
{
    public function test_read_only_default(): void
    {
        $permissions = Permissions::readOnly();

        $this->assertSame(['contents' => 'read'], $permissions->toArray());
    }

    public function test_with_adds_permission(): void
    {
        $permissions = Permissions::readOnly()
            ->with(new Permission(PermissionScope::IdToken, PermissionAccess::Write));

        $array = $permissions->toArray();

        $this->assertSame('read', $array['contents']);
        $this->assertSame('write', $array['id-token']);
    }

    public function test_with_replaces_existing_scope(): void
    {
        $permissions = Permissions::readOnly()
            ->with(new Permission(PermissionScope::Contents, PermissionAccess::Write));

        $this->assertSame(['contents' => 'write'], $permissions->toArray());
    }

    public function test_merge(): void
    {
        $a = Permissions::readOnly();
        $b = new Permissions([
            new Permission(PermissionScope::Packages, PermissionAccess::Write),
            new Permission(PermissionScope::IdToken, PermissionAccess::Write),
        ]);

        $merged = $a->merge($b);

        $array = $merged->toArray();
        $this->assertSame('read', $array['contents']);
        $this->assertSame('write', $array['id-token']);
        $this->assertSame('write', $array['packages']);
    }

    public function test_to_array_sorted(): void
    {
        $permissions = new Permissions([
            new Permission(PermissionScope::Packages, PermissionAccess::Write),
            new Permission(PermissionScope::Actions, PermissionAccess::Read),
            new Permission(PermissionScope::Contents, PermissionAccess::Read),
        ]);

        $keys = array_keys($permissions->toArray());

        $this->assertSame(['actions', 'contents', 'packages'], $keys);
    }

    public function test_duplicate_scope_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Permissions([
            new Permission(PermissionScope::Contents, PermissionAccess::Read),
            new Permission(PermissionScope::Contents, PermissionAccess::Write),
        ]);
    }

    public function test_empty_permissions(): void
    {
        $permissions = new Permissions();

        $this->assertTrue($permissions->isEmpty());
        $this->assertSame([], $permissions->toArray());
    }
}
