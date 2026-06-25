<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Storage\InMemoryScimGroupStorage;
use Vortos\Auth\Scim\Storage\InMemoryScimUserStorage;
use Vortos\Tenant\Exception\MissingTenantContextException;
use Vortos\Tenant\TenantContext;

/**
 * Regression tests: SCIM provisioning must be strictly tenant-scoped.
 *
 * Every test creates resources in one tenant and verifies they are invisible
 * to another tenant. Covers the cross-tenant IDOR attack surface documented
 * in SECURITY_AUDIT_AUTH.md #2.
 */
final class ScimTenantIsolationTest extends TestCase
{
    private InMemoryScimUserStorage $userStorage;
    private InMemoryScimGroupStorage $groupStorage;
    private TenantContext $tenantContext;
    private ScimService $service;

    protected function setUp(): void
    {
        $this->userStorage   = new InMemoryScimUserStorage();
        $this->groupStorage  = new InMemoryScimGroupStorage();
        $this->tenantContext = new TenantContext();
        $this->service       = new ScimService($this->userStorage, $this->groupStorage, $this->tenantContext);
    }

    // ── Fail-closed: no tenant → exception ──

    public function test_create_user_throws_without_tenant_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        $this->service->createUser(['userName' => 'orphan@example.com']);
    }

    public function test_list_users_throws_without_tenant_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        $this->service->listUsers();
    }

    public function test_create_group_throws_without_tenant_context(): void
    {
        $this->expectException(MissingTenantContextException::class);
        $this->service->createGroup(['displayName' => 'Orphan Group']);
    }

    // ── User isolation ──

    public function test_user_created_in_tenant_a_invisible_to_tenant_b(): void
    {
        $this->tenantContext->set('tenant-a');
        $user = $this->service->createUser($this->userPayload('alice@example.com'));

        $this->tenantContext->set('tenant-b');
        $this->assertNull($this->service->getUser($user->id));
    }

    public function test_list_users_only_returns_current_tenant(): void
    {
        $this->tenantContext->set('tenant-a');
        $this->service->createUser($this->userPayload('a1@example.com'));
        $this->service->createUser($this->userPayload('a2@example.com'));

        $this->tenantContext->set('tenant-b');
        $this->service->createUser($this->userPayload('b1@example.com'));

        $listB = $this->service->listUsers();
        $this->assertSame(1, $listB['totalResults']);

        $this->tenantContext->set('tenant-a');
        $listA = $this->service->listUsers();
        $this->assertSame(2, $listA['totalResults']);
    }

    public function test_delete_user_from_wrong_tenant_returns_false(): void
    {
        $this->tenantContext->set('tenant-a');
        $user = $this->service->createUser($this->userPayload('protected@example.com'));

        $this->tenantContext->set('tenant-b');
        $this->assertFalse($this->service->deleteUser($user->id));

        $this->tenantContext->set('tenant-a');
        $this->assertNotNull($this->service->getUser($user->id), 'User must survive cross-tenant delete attempt');
    }

    public function test_patch_user_from_wrong_tenant_returns_null(): void
    {
        $this->tenantContext->set('tenant-a');
        $user = $this->service->createUser($this->userPayload('safe@example.com'));

        $this->tenantContext->set('tenant-b');
        $result = $this->service->patchUser($user->id, [
            ['op' => 'replace', 'path' => 'active', 'value' => false],
        ]);
        $this->assertNull($result);

        $this->tenantContext->set('tenant-a');
        $this->assertTrue($this->service->getUser($user->id)->active, 'Cross-tenant patch must not modify user');
    }

    public function test_replace_user_from_wrong_tenant_does_not_overwrite(): void
    {
        $this->tenantContext->set('tenant-a');
        $user = $this->service->createUser($this->userPayload('original@example.com'));

        $this->tenantContext->set('tenant-b');
        $result = $this->service->replaceUser($user->id, $this->userPayload('attacker@example.com'));
        $this->assertNull($result, 'replaceUser must return null for a user in another tenant');

        $this->tenantContext->set('tenant-a');
        $reloaded = $this->service->getUser($user->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('original@example.com', $reloaded->userName, 'Cross-tenant replace must not modify original');
    }

    // ── findByExternalId isolation ──

    public function test_find_by_external_id_scoped_per_tenant(): void
    {
        $this->tenantContext->set('tenant-a');
        $this->service->createUser($this->userPayload('a@example.com', externalId: 'shared-ext-id'));

        $this->tenantContext->set('tenant-b');
        $userB = $this->service->createUser($this->userPayload('b@example.com', externalId: 'shared-ext-id'));

        $this->assertSame('b@example.com', $userB->userName);
        $this->assertSame('tenant-b', $userB->tenantId);

        $this->tenantContext->set('tenant-a');
        $userA = $this->service->getUser(
            $this->userStorage->findByExternalId('tenant-a', 'shared-ext-id')->id
        );
        $this->assertSame('a@example.com', $userA->userName);
    }

    public function test_idempotent_create_does_not_cross_tenant_boundary(): void
    {
        $this->tenantContext->set('tenant-a');
        $userA = $this->service->createUser($this->userPayload('a@example.com', externalId: 'ext-123'));

        $this->tenantContext->set('tenant-b');
        $userB = $this->service->createUser($this->userPayload('b@example.com', externalId: 'ext-123'));

        $this->assertNotSame($userA->id, $userB->id, 'Same externalId in different tenants must create separate users');
        $this->assertSame('tenant-a', $userA->tenantId);
        $this->assertSame('tenant-b', $userB->tenantId);
    }

    // ── findByUserName isolation ──

    public function test_find_by_username_scoped_per_tenant(): void
    {
        $this->tenantContext->set('tenant-a');
        $this->service->createUser($this->userPayload('shared@example.com'));

        $this->tenantContext->set('tenant-b');
        $found = $this->userStorage->findByUserName('tenant-b', 'shared@example.com');
        $this->assertNull($found, 'userName lookup must not cross tenant boundary');
    }

    // ── Group isolation ──

    public function test_group_created_in_tenant_a_invisible_to_tenant_b(): void
    {
        $this->tenantContext->set('tenant-a');
        $group = $this->service->createGroup(['displayName' => 'Admins', 'externalId' => 'grp-1']);

        $this->tenantContext->set('tenant-b');
        $this->assertNull($this->service->getGroup($group->id));
    }

    public function test_list_groups_only_returns_current_tenant(): void
    {
        $this->tenantContext->set('tenant-a');
        $this->service->createGroup(['displayName' => 'Group A1']);
        $this->service->createGroup(['displayName' => 'Group A2']);

        $this->tenantContext->set('tenant-b');
        $this->service->createGroup(['displayName' => 'Group B1']);

        $this->assertSame(1, $this->service->listGroups()['totalResults']);

        $this->tenantContext->set('tenant-a');
        $this->assertSame(2, $this->service->listGroups()['totalResults']);
    }

    public function test_delete_group_from_wrong_tenant_returns_false(): void
    {
        $this->tenantContext->set('tenant-a');
        $group = $this->service->createGroup(['displayName' => 'Protected']);

        $this->tenantContext->set('tenant-b');
        $this->assertFalse($this->service->deleteGroup($group->id));

        $this->tenantContext->set('tenant-a');
        $this->assertNotNull($this->service->getGroup($group->id));
    }

    public function test_patch_group_from_wrong_tenant_returns_null(): void
    {
        $this->tenantContext->set('tenant-a');
        $group = $this->service->createGroup(['displayName' => 'Original']);

        $this->tenantContext->set('tenant-b');
        $result = $this->service->patchGroup($group->id, [
            ['op' => 'replace', 'path' => 'displayname', 'value' => 'Hacked'],
        ]);
        $this->assertNull($result);

        $this->tenantContext->set('tenant-a');
        $this->assertSame('Original', $this->service->getGroup($group->id)->displayName);
    }

    public function test_idempotent_group_create_does_not_cross_tenant_boundary(): void
    {
        $this->tenantContext->set('tenant-a');
        $groupA = $this->service->createGroup(['displayName' => 'Shared Name', 'externalId' => 'ext-grp']);

        $this->tenantContext->set('tenant-b');
        $groupB = $this->service->createGroup(['displayName' => 'Shared Name', 'externalId' => 'ext-grp']);

        $this->assertNotSame($groupA->id, $groupB->id);
    }

    // ── Tenant stamped on domain objects ──

    public function test_created_user_carries_tenant_id(): void
    {
        $this->tenantContext->set('tenant-x');
        $user = $this->service->createUser($this->userPayload('stamped@example.com'));
        $this->assertSame('tenant-x', $user->tenantId);
    }

    public function test_created_group_carries_tenant_id(): void
    {
        $this->tenantContext->set('tenant-y');
        $group = $this->service->createGroup(['displayName' => 'Stamped Group']);
        $this->assertSame('tenant-y', $group->tenantId);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function userPayload(string $userName, string $externalId = ''): array
    {
        return [
            'userName'    => $userName,
            'externalId'  => $externalId,
            'displayName' => 'Test User',
            'name'        => ['givenName' => 'Test', 'familyName' => 'User'],
            'emails'      => [['type' => 'work', 'value' => $userName]],
            'active'      => true,
        ];
    }
}
