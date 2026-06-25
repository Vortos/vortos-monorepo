<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use Vortos\Pipeline\Model\Permission;
use Vortos\Pipeline\Model\PermissionAccess;
use Vortos\Pipeline\Model\PermissionScope;

final class PermissionTest extends TestCase
{
    public function test_all_scope_access_combos(): void
    {
        foreach (PermissionScope::cases() as $scope) {
            foreach (PermissionAccess::cases() as $access) {
                $permission = new Permission($scope, $access);

                $this->assertSame($scope, $permission->scope);
                $this->assertSame($access, $permission->access);

                $array = $permission->toArray();
                $this->assertSame($scope->value, $array['scope']);
                $this->assertSame($access->value, $array['access']);
            }
        }
    }
}
