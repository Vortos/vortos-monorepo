<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Domain\ScimGroup;
use Vortos\Auth\Scim\Domain\ScimUser;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapper;
use Vortos\Auth\Scim\Sso\ClaimsRoleMapping;
use Vortos\Auth\Scim\Storage\InMemoryScimGroupStorage;
use Vortos\Auth\Scim\Storage\InMemoryScimUserStorage;

/**
 * Block 29 — SCIM 2.0 service tests (RFC 7643/7644).
 */
final class ScimServiceTest extends TestCase
{
    private InMemoryScimUserStorage $userStorage;
    private InMemoryScimGroupStorage $groupStorage;
    private ScimService $service;

    protected function setUp(): void
    {
        $this->userStorage  = new InMemoryScimUserStorage();
        $this->groupStorage = new InMemoryScimGroupStorage();
        $this->service      = new ScimService($this->userStorage, $this->groupStorage);
    }

    // -------------------------------------------------------------------------
    // User CRUD
    // -------------------------------------------------------------------------

    public function test_create_user(): void
    {
        $user = $this->service->createUser($this->userPayload('alice@example.com'));

        $this->assertSame('alice@example.com', $user->userName);
        $this->assertTrue($user->active);
        $this->assertNotEmpty($user->id);
    }

    public function test_create_user_is_idempotent_on_external_id(): void
    {
        $payload = $this->userPayload('bob@example.com', externalId: 'idp-bob-123');
        $first   = $this->service->createUser($payload);
        $second  = $this->service->createUser($payload); // second call with same externalId

        $this->assertSame($first->id, $second->id, 'Same externalId must map to same user');
    }

    public function test_get_user_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->service->getUser('nonexistent'));
    }

    public function test_list_users_returns_all_users(): void
    {
        $this->service->createUser($this->userPayload('user1@example.com'));
        $this->service->createUser($this->userPayload('user2@example.com'));

        $list = $this->service->listUsers();
        $this->assertSame(2, $list['totalResults']);
        $this->assertCount(2, $list['Resources']);
    }

    public function test_list_users_filter_by_username(): void
    {
        $this->service->createUser($this->userPayload('alice@example.com'));
        $this->service->createUser($this->userPayload('bob@example.com'));

        $list = $this->service->listUsers('userName eq "alice@example.com"');
        $this->assertSame(1, $list['totalResults']);
        $this->assertSame('alice@example.com', $list['Resources'][0]['userName']);
    }

    public function test_list_users_pagination(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->service->createUser($this->userPayload("user{$i}@example.com"));
        }

        $page1 = $this->service->listUsers(null, 1, 5);
        $this->assertSame(10, $page1['totalResults']);
        $this->assertSame(5, $page1['itemsPerPage']);

        $page2 = $this->service->listUsers(null, 6, 5);
        $this->assertSame(5, $page2['itemsPerPage']);
    }

    public function test_replace_user(): void
    {
        $user    = $this->service->createUser($this->userPayload('orig@example.com'));
        $updated = $this->service->replaceUser($user->id, $this->userPayload('updated@example.com'));

        $this->assertSame('updated@example.com', $updated->userName);
    }

    public function test_patch_user_deactivate(): void
    {
        $user = $this->service->createUser($this->userPayload('active@example.com'));
        $this->assertTrue($user->active);

        $patched = $this->service->patchUser($user->id, [
            ['op' => 'replace', 'path' => 'active', 'value' => false],
        ]);

        $this->assertNotNull($patched);
        $this->assertFalse($patched->active);
    }

    public function test_patch_user_deactivate_via_value_map(): void
    {
        $user    = $this->service->createUser($this->userPayload('active2@example.com'));
        $patched = $this->service->patchUser($user->id, [
            ['op' => 'replace', 'path' => '', 'value' => ['active' => false]],
        ]);

        $this->assertFalse($patched->active);
    }

    public function test_patch_user_returns_null_for_unknown_id(): void
    {
        $result = $this->service->patchUser('unknown', [['op' => 'replace', 'path' => 'active', 'value' => false]]);
        $this->assertNull($result);
    }

    public function test_delete_user(): void
    {
        $user = $this->service->createUser($this->userPayload('del@example.com'));
        $this->assertTrue($this->service->deleteUser($user->id));
        $this->assertNull($this->service->getUser($user->id));
    }

    public function test_delete_nonexistent_user_returns_false(): void
    {
        $this->assertFalse($this->service->deleteUser('ghost'));
    }

    // -------------------------------------------------------------------------
    // Deactivation revokes flag-management access
    // -------------------------------------------------------------------------

    public function test_deactivated_user_has_active_false(): void
    {
        $user = $this->service->createUser($this->userPayload('will-deactivate@example.com'));
        $this->service->patchUser($user->id, [['op' => 'replace', 'path' => 'active', 'value' => false]]);

        $reloaded = $this->service->getUser($user->id);
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->active, 'Deactivated user must have active=false; authz gate uses this to deny access');
    }

    // -------------------------------------------------------------------------
    // Group CRUD
    // -------------------------------------------------------------------------

    public function test_create_group(): void
    {
        $group = $this->service->createGroup(['displayName' => 'Flags Admins', 'externalId' => 'idp-grp-1']);

        $this->assertSame('Flags Admins', $group->displayName);
        $this->assertNotEmpty($group->id);
    }

    public function test_create_group_idempotent_on_external_id(): void
    {
        $data   = ['displayName' => 'Idempotent Group', 'externalId' => 'idp-grp-idem'];
        $first  = $this->service->createGroup($data);
        $second = $this->service->createGroup($data);

        $this->assertSame($first->id, $second->id);
    }

    public function test_list_groups(): void
    {
        $this->service->createGroup(['displayName' => 'Group A']);
        $this->service->createGroup(['displayName' => 'Group B']);

        $list = $this->service->listGroups();
        $this->assertSame(2, $list['totalResults']);
    }

    public function test_patch_group_add_members(): void
    {
        $user  = $this->service->createUser($this->userPayload('member@example.com'));
        $group = $this->service->createGroup(['displayName' => 'Team Alpha']);

        $patched = $this->service->patchGroup($group->id, [
            ['op' => 'add', 'path' => 'members', 'value' => [['value' => $user->id]]],
        ]);

        $this->assertNotNull($patched);
        $this->assertContains($user->id, $patched->memberIds);
    }

    public function test_patch_group_remove_members(): void
    {
        $user  = $this->service->createUser($this->userPayload('removeme@example.com'));
        $group = $this->service->createGroup(['displayName' => 'Team Beta', 'members' => [['value' => $user->id]]]);

        $patched = $this->service->patchGroup($group->id, [
            ['op' => 'remove', 'path' => 'members', 'value' => [['value' => $user->id]]],
        ]);

        $this->assertNotNull($patched);
        $this->assertNotContains($user->id, $patched->memberIds);
    }

    public function test_delete_group(): void
    {
        $group = $this->service->createGroup(['displayName' => 'To Delete']);
        $this->assertTrue($this->service->deleteGroup($group->id));
        $this->assertNull($this->service->getGroup($group->id));
    }

    // -------------------------------------------------------------------------
    // SCIM User wire format
    // -------------------------------------------------------------------------

    public function test_scim_user_wire_format_includes_required_fields(): void
    {
        $user = $this->service->createUser($this->userPayload('wire@example.com'));
        $wire = $user->toScimArray('https://api.example.com/scim/v2');

        $this->assertSame(ScimUser::SCHEMAS, $wire['schemas']);
        $this->assertArrayHasKey('id', $wire);
        $this->assertArrayHasKey('userName', $wire);
        $this->assertArrayHasKey('active', $wire);
        $this->assertArrayHasKey('meta', $wire);
        $this->assertSame('User', $wire['meta']['resourceType']);
        $this->assertStringContainsString('/Users/', $wire['meta']['location']);
    }

    public function test_scim_group_wire_format_includes_required_fields(): void
    {
        $group = $this->service->createGroup(['displayName' => 'Wire Group']);
        $wire  = $group->toScimArray('https://api.example.com/scim/v2');

        $this->assertSame(ScimGroup::SCHEMAS, $wire['schemas']);
        $this->assertSame('Group', $wire['meta']['resourceType']);
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
