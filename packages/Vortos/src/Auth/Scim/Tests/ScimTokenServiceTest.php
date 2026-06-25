<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Token\InMemoryScimTokenStorage;
use Vortos\Auth\Scim\Token\ScimTokenService;

final class ScimTokenServiceTest extends TestCase
{
    private ScimTokenService $service;
    private InMemoryScimTokenStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryScimTokenStorage();
        $this->service = new ScimTokenService($this->storage);
    }

    public function test_issue_returns_raw_token_and_record(): void
    {
        $result = $this->service->issue('tenant-1', ['scim:users:read']);

        $this->assertArrayHasKey('raw', $result);
        $this->assertArrayHasKey('record', $result);
        $this->assertStringStartsWith('vsct_', $result['raw']);
        $this->assertSame('tenant-1', $result['record']->tenantId);
        $this->assertSame(['scim:users:read'], $result['record']->scopes);
        $this->assertTrue($result['record']->active);
    }

    public function test_validate_returns_record_for_valid_token(): void
    {
        $result = $this->service->issue('tenant-1', ['scim:users:read']);
        $record = $this->service->validate($result['raw']);

        $this->assertNotNull($record);
        $this->assertSame($result['record']->id, $record->id);
    }

    public function test_validate_returns_null_for_unknown_token(): void
    {
        $this->assertNull($this->service->validate('vsct_unknown'));
    }

    public function test_validate_returns_null_for_empty_token(): void
    {
        $this->assertNull($this->service->validate(''));
    }

    public function test_validate_returns_null_for_expired_token(): void
    {
        $result = $this->service->issue(
            'tenant-1',
            [],
            [],
            new \DateTimeImmutable('-1 hour'),
        );

        $this->assertNull($this->service->validate($result['raw']));
    }

    public function test_validate_returns_null_after_revocation(): void
    {
        $result = $this->service->issue('tenant-1');
        $this->service->revoke($result['record']->id);

        $this->assertNull($this->service->validate($result['raw']));
    }

    public function test_rotate_revokes_old_and_issues_new(): void
    {
        $old = $this->service->issue('tenant-1', ['scim:users:read']);
        $new = $this->service->rotate(
            $old['record']->id,
            'tenant-1',
            ['scim:users:read', 'scim:users:write'],
            [],
        );

        $this->assertNull($this->service->validate($old['raw']), 'Old token must be revoked');
        $this->assertNotNull($this->service->validate($new['raw']), 'New token must be valid');
        $this->assertSame(['scim:users:read', 'scim:users:write'], $new['record']->scopes);
    }

    public function test_list_for_tenant_returns_only_active_tokens(): void
    {
        $t1a = $this->service->issue('tenant-1');
        $t1b = $this->service->issue('tenant-1');
        $this->service->issue('tenant-2');
        $this->service->revoke($t1b['record']->id);

        $list = $this->service->listForTenant('tenant-1');
        $this->assertCount(1, $list);
        $this->assertSame($t1a['record']->id, $list[0]->id);
    }

    public function test_issue_with_cidr_allowlist(): void
    {
        $result = $this->service->issue('tenant-1', [], ['10.0.0.0/8', '192.168.1.0/24']);

        $this->assertSame(['10.0.0.0/8', '192.168.1.0/24'], $result['record']->allowedCidrs);
    }

    public function test_raw_token_is_unique_per_issue(): void
    {
        $a = $this->service->issue('tenant-1');
        $b = $this->service->issue('tenant-1');

        $this->assertNotSame($a['raw'], $b['raw']);
    }

    public function test_token_hash_is_sha256_of_raw(): void
    {
        $result = $this->service->issue('tenant-1');
        $expected = hash('sha256', $result['raw']);

        $this->assertSame($expected, $result['record']->hashedToken);
    }

    public function test_validate_updates_last_used_at(): void
    {
        $result = $this->service->issue('tenant-1');
        $this->assertNull($result['record']->lastUsedAt);

        $this->service->validate($result['raw']);
        $list = $this->service->listForTenant('tenant-1');

        $this->assertNotNull($list[0]->lastUsedAt);
    }

    public function test_scope_checks_on_record(): void
    {
        $result = $this->service->issue('tenant-1', ['scim:users:read', 'scim:groups:write']);
        $record = $result['record'];

        $this->assertTrue($record->hasScope('scim:users:read'));
        $this->assertTrue($record->hasScope('scim:groups:write'));
        $this->assertFalse($record->hasScope('scim:users:write'));
        $this->assertTrue($record->hasAllScopes(['scim:users:read', 'scim:groups:write']));
        $this->assertFalse($record->hasAllScopes(['scim:users:read', 'scim:users:write']));
    }

    public function test_expiry_check_on_record(): void
    {
        $future = $this->service->issue('tenant-1', [], [], new \DateTimeImmutable('+1 hour'));
        $this->assertFalse($future['record']->isExpired());

        $past = $this->service->issue('tenant-1', [], [], new \DateTimeImmutable('-1 hour'));
        $this->assertTrue($past['record']->isExpired());
    }
}
