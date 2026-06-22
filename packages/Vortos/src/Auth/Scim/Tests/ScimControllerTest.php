<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Http\ScimController;
use Vortos\Auth\Scim\Http\ScimDiscoveryController;
use Vortos\Auth\Scim\ScimService;
use Vortos\Auth\Scim\Storage\InMemoryScimGroupStorage;
use Vortos\Auth\Scim\Storage\InMemoryScimUserStorage;

final class ScimControllerTest extends TestCase
{
    private ScimController $controller;
    private ScimDiscoveryController $discovery;

    protected function setUp(): void
    {
        $service         = new ScimService(new InMemoryScimUserStorage(), new InMemoryScimGroupStorage());
        $this->controller = new ScimController($service, 'https://api.example.com/scim/v2');
        $this->discovery  = new ScimDiscoveryController('https://api.example.com/scim/v2');
    }

    public function test_create_user_returns_201(): void
    {
        $body   = json_encode(['userName' => 'foo@example.com', 'displayName' => 'Foo', 'active' => true]);
        $result = $this->controller->createUser($body);

        $this->assertSame(201, $result['status']);
        $this->assertSame('foo@example.com', $result['body']['userName']);
    }

    public function test_create_user_missing_username_returns_400(): void
    {
        $result = $this->controller->createUser(json_encode(['displayName' => 'No Username']));
        $this->assertSame(400, $result['status']);
    }

    public function test_create_user_invalid_json_returns_400(): void
    {
        $result = $this->controller->createUser('not-json{');
        $this->assertSame(400, $result['status']);
        $this->assertSame('invalidSyntax', $result['body']['scimType']);
    }

    public function test_get_user_returns_200_for_existing(): void
    {
        $created = $this->controller->createUser(json_encode(['userName' => 'get-me@example.com']));
        $id      = $created['body']['id'];

        $result = $this->controller->getUser($id);
        $this->assertSame(200, $result['status']);
        $this->assertSame('get-me@example.com', $result['body']['userName']);
    }

    public function test_get_user_returns_404_for_unknown(): void
    {
        $result = $this->controller->getUser('ghost-id');
        $this->assertSame(404, $result['status']);
    }

    public function test_list_users_returns_list_response(): void
    {
        $this->controller->createUser(json_encode(['userName' => 'u1@example.com']));
        $this->controller->createUser(json_encode(['userName' => 'u2@example.com']));

        $result = $this->controller->listUsers(null, 1, 100);
        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['totalResults']);
    }

    public function test_patch_user_deactivate_returns_200(): void
    {
        $created = $this->controller->createUser(json_encode(['userName' => 'patch-me@example.com']));
        $id      = $created['body']['id'];

        $patch  = json_encode(['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        $result = $this->controller->patchUser($id, $patch);

        $this->assertSame(200, $result['status']);
        $this->assertFalse($result['body']['active']);
    }

    public function test_patch_user_returns_404_for_unknown(): void
    {
        $patch  = json_encode(['Operations' => [['op' => 'replace', 'path' => 'active', 'value' => false]]]);
        $result = $this->controller->patchUser('ghost', $patch);
        $this->assertSame(404, $result['status']);
    }

    public function test_delete_user_returns_204(): void
    {
        $created = $this->controller->createUser(json_encode(['userName' => 'del@example.com']));
        $id      = $created['body']['id'];

        $result = $this->controller->deleteUser($id);
        $this->assertSame(204, $result['status']);
    }

    public function test_delete_nonexistent_user_returns_404(): void
    {
        $result = $this->controller->deleteUser('ghost');
        $this->assertSame(404, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    public function test_create_group_returns_201(): void
    {
        $result = $this->controller->createGroup(json_encode(['displayName' => 'Engineering']));
        $this->assertSame(201, $result['status']);
    }

    public function test_create_group_missing_display_name_returns_400(): void
    {
        $result = $this->controller->createGroup(json_encode([]));
        $this->assertSame(400, $result['status']);
    }

    public function test_patch_group_add_member(): void
    {
        $user  = $this->controller->createUser(json_encode(['userName' => 'member@example.com']));
        $group = $this->controller->createGroup(json_encode(['displayName' => 'Team']));
        $uid   = $user['body']['id'];
        $gid   = $group['body']['id'];

        $patch  = json_encode(['Operations' => [['op' => 'add', 'path' => 'members', 'value' => [['value' => $uid]]]]]);
        $result = $this->controller->patchGroup($gid, $patch);

        $this->assertSame(200, $result['status']);
    }

    public function test_delete_group_returns_204(): void
    {
        $created = $this->controller->createGroup(json_encode(['displayName' => 'To Delete']));
        $result  = $this->controller->deleteGroup($created['body']['id']);
        $this->assertSame(204, $result['status']);
    }

    // -------------------------------------------------------------------------
    // Discovery
    // -------------------------------------------------------------------------

    public function test_service_provider_config_includes_patch_support(): void
    {
        $config = $this->discovery->serviceProviderConfig();
        $this->assertTrue($config['patch']['supported']);
    }

    public function test_resource_types_includes_user_and_group(): void
    {
        $types = $this->discovery->resourceTypes();
        $names = array_column($types['Resources'], 'name');
        $this->assertContains('User', $names);
        $this->assertContains('Group', $names);
    }

    public function test_schemas_includes_both_schemas(): void
    {
        $schemas = $this->discovery->schemas();
        $this->assertSame(2, $schemas['totalResults']);
    }
}
