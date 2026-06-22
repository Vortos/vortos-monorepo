<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\SdkKey;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\SdkKey\IpAllowlistChecker;
use Vortos\FeatureFlags\SdkKey\SdkKey;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\FeatureFlags\SdkKey\Storage\SdkKeyStorageInterface;

final class SdkKeyServiceTest extends TestCase
{
    private SdkKeyStorageInterface $storage;
    private SdkKeyService $service;

    protected function setUp(): void
    {
        $this->storage = $this->createMock(SdkKeyStorageInterface::class);
        $this->service = new SdkKeyService($this->storage, new IpAllowlistChecker());
    }

    public function test_issue_returns_raw_key_and_sdk_key(): void
    {
        $this->storage->expects($this->once())->method('save');

        $result = $this->service->issue('test', 'proj1', 'prod', SdkKey::KIND_SERVER, 'user1', null, null);

        $this->assertArrayHasKey('rawKey', $result);
        $this->assertArrayHasKey('sdkKey', $result);
        $this->assertStringStartsWith('vff_srv_', $result['rawKey']);
        $this->assertNotSame($result['sdkKey']->keyHash, $result['rawKey']);
    }

    public function test_raw_key_is_never_stored_only_hash(): void
    {
        $savedKey = null;
        $this->storage->method('save')->willReturnCallback(function (SdkKey $k) use (&$savedKey) {
            $savedKey = $k;
        });

        $result = $this->service->issue('test', 'p', 'e', SdkKey::KIND_SERVER, 'u', null, null);

        $this->assertNotNull($savedKey);
        $this->assertNotSame($result['rawKey'], $savedKey->keyHash);
        $this->assertSame(hash('sha256', $result['rawKey']), $savedKey->keyHash);
    }

    public function test_client_key_has_cli_prefix(): void
    {
        $this->storage->method('save');

        $result = $this->service->issue('test', 'p', 'e', SdkKey::KIND_CLIENT, 'u', null, null);

        $this->assertStringStartsWith('vff_cli_', $result['rawKey']);
    }

    public function test_validate_valid_key_returns_sdk_key(): void
    {
        $result = $this->issueAndCapture('proj1', 'prod');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);
        $this->storage->expects($this->once())->method('updateLastUsed');

        $validated = $this->service->validate($rawKey, 'proj1', 'prod');

        $this->assertNotNull($validated);
        $this->assertSame($sdkKey->id, $validated->id);
    }

    public function test_validate_wrong_project_returns_null(): void
    {
        $result = $this->issueAndCapture('proj1', 'prod');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);

        $validated = $this->service->validate($rawKey, 'wrong-proj', 'prod');

        $this->assertNull($validated);
    }

    public function test_validate_wrong_environment_returns_null(): void
    {
        $result = $this->issueAndCapture('proj1', 'prod');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);

        $validated = $this->service->validate($rawKey, 'proj1', 'staging');

        $this->assertNull($validated);
    }

    public function test_validate_revoked_key_returns_null(): void
    {
        $result = $this->issueAndCapture('proj1', 'prod');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];
        $revoked = new SdkKey(
            id: $sdkKey->id, name: $sdkKey->name, keyPrefix: $sdkKey->keyPrefix,
            keyHash: $sdkKey->keyHash, kind: $sdkKey->kind, projectId: $sdkKey->projectId,
            environment: $sdkKey->environment, createdAt: $sdkKey->createdAt,
            createdBy: $sdkKey->createdBy, revokedAt: new \DateTimeImmutable('-1 hour'),
        );

        $this->storage->method('findByPrefix')->willReturn([$revoked]);

        $this->assertNull($this->service->validate($rawKey, 'proj1', 'prod'));
    }

    public function test_validate_expired_key_returns_null(): void
    {
        $result = $this->issueAndCapture('proj1', 'prod');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];
        $expired = new SdkKey(
            id: $sdkKey->id, name: $sdkKey->name, keyPrefix: $sdkKey->keyPrefix,
            keyHash: $sdkKey->keyHash, kind: $sdkKey->kind, projectId: $sdkKey->projectId,
            environment: $sdkKey->environment, createdAt: $sdkKey->createdAt,
            createdBy: $sdkKey->createdBy, expiresAt: new \DateTimeImmutable('-1 hour'),
        );

        $this->storage->method('findByPrefix')->willReturn([$expired]);

        $this->assertNull($this->service->validate($rawKey, 'proj1', 'prod'));
    }

    public function test_validate_invalid_key_returns_null(): void
    {
        $this->storage->method('findByPrefix')->willReturn([]);

        $this->assertNull($this->service->validate('vff_srv_totally_invalid_key_xxxx', 'p', 'e'));
    }

    public function test_validate_update_last_used_called_on_success(): void
    {
        $result = $this->issueAndCapture('p', 'e');
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);
        $this->storage->expects($this->once())->method('updateLastUsed')->with($sdkKey->id);

        $this->service->validate($rawKey, 'p', 'e');
    }

    public function test_validate_ip_allowlist_blocks_disallowed_ip(): void
    {
        $result = $this->issueAndCapture('p', 'e', ['192.168.1.0/24']);
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);

        $this->assertNull($this->service->validate($rawKey, 'p', 'e', '10.0.0.1'));
    }

    public function test_validate_ip_allowlist_allows_matching_ip(): void
    {
        $result = $this->issueAndCapture('p', 'e', ['192.168.1.0/24']);
        $rawKey = $result['rawKey'];
        $sdkKey = $result['sdkKey'];

        $this->storage->method('findByPrefix')->willReturn([$sdkKey]);
        $this->storage->method('updateLastUsed');

        $this->assertNotNull($this->service->validate($rawKey, 'p', 'e', '192.168.1.50'));
    }

    public function test_rotate_issues_successor_with_grace_period(): void
    {
        $existingKey = $this->buildSdkKey('old-id', 'p', 'e');
        $this->storage->method('findById')->willReturn($existingKey);
        $this->storage->expects($this->exactly(2))->method('save');

        $result = $this->service->rotate('old-id', 'actor1');

        $this->assertArrayHasKey('rawKey', $result);
        $this->assertStringStartsWith('vff_srv_', $result['rawKey']);
    }

    public function test_revoke_calls_storage_revoke(): void
    {
        $this->storage->expects($this->once())->method('revoke')->with('key-id');

        $this->service->revoke('key-id', 'actor');
    }

    // --- helpers ---

    private function issueAndCapture(string $projectId, string $environment, ?array $ipAllowlist = null): array
    {
        $savedKey = null;
        $this->storage->method('save')->willReturnCallback(function (SdkKey $k) use (&$savedKey) {
            if ($savedKey === null) {
                $savedKey = $k;
            }
        });

        $result = $this->service->issue('test', $projectId, $environment, SdkKey::KIND_SERVER, 'u', $ipAllowlist, null);

        return ['rawKey' => $result['rawKey'], 'sdkKey' => $result['sdkKey']];
    }

    private function buildSdkKey(string $id, string $projectId, string $environment): SdkKey
    {
        return new SdkKey(
            id: $id, name: 'test', keyPrefix: 'abc123456789',
            keyHash: hash('sha256', 'dummy'), kind: SdkKey::KIND_SERVER,
            projectId: $projectId, environment: $environment,
            createdAt: new \DateTimeImmutable(), createdBy: 'u',
        );
    }
}
