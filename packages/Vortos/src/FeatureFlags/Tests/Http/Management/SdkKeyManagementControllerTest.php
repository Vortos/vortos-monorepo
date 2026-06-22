<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Http\Management;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Cqrs\Validation\VortosValidator;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\Management\ManagementResponseFactory;
use Vortos\FeatureFlags\Http\Management\SdkKeyManagementController;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\SdkKey\SdkKey;
use Vortos\FeatureFlags\SdkKey\SdkKeyService;
use Vortos\FeatureFlags\SdkKey\Storage\SdkKeyStorageInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;

final class SdkKeyManagementControllerTest extends TestCase
{
    private SdkKeyService $sdkKeyService;
    private SdkKeyStorageInterface $sdkKeyStorage;
    private ManagementAuthzGateInterface $authz;
    private FlagRateLimitService $rateLimit;
    private CurrentUserProvider $currentUser;
    private SdkKeyManagementController $controller;

    protected function setUp(): void
    {
        $this->sdkKeyService  = $this->createMock(SdkKeyService::class);
        $this->sdkKeyStorage  = $this->createMock(SdkKeyStorageInterface::class);
        $this->authz          = $this->createMock(ManagementAuthzGateInterface::class);
        $this->rateLimit      = $this->createMock(FlagRateLimitService::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-user', ['ROLE_ADMIN']));
        $this->currentUser = new CurrentUserProvider($adapter);

        $validator = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());

        $this->controller = new SdkKeyManagementController(
            sdkKeyService:  $this->sdkKeyService,
            sdkKeyStorage:  $this->sdkKeyStorage,
            authz:          $this->authz,
            rateLimit:      $this->rateLimit,
            response:       new ManagementResponseFactory(),
            currentUser:    $this->currentUser,
            validator:      $validator,
        );
    }

    public function test_issue_returns_201_with_raw_key(): void
    {
        $this->authz->method('requirePermission');

        $sdkKey = $this->buildSdkKey('key-1');
        $this->sdkKeyService->method('issue')->willReturn([
            'rawKey' => 'vff_srv_rawkeyvalue12345678901234',
            'sdkKey' => $sdkKey,
        ]);

        $request  = $this->jsonRequest([
            'name'        => 'My Key',
            'projectId'   => 'proj1',
            'environment' => 'prod',
        ]);
        $response = $this->controller->issue($request);
        $body     = $this->decode($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayHasKey('rawKey', $body['data']);
        $this->assertArrayNotHasKey('keyHash', $body['data']['key']);
    }

    public function test_list_shows_prefix_only_no_hash(): void
    {
        $this->authz->method('requirePermission');
        $sdkKey = $this->buildSdkKey('key-1');
        $this->sdkKeyStorage->method('findByProjectAndEnv')->willReturn([$sdkKey]);

        $request  = Request::create('/api/management/v1/keys?projectId=p&environment=e', 'GET');
        $response = $this->controller->list($request);
        $body     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertArrayHasKey('keyPrefix', $body['data'][0]);
        $this->assertArrayNotHasKey('keyHash', $body['data'][0]);
    }

    public function test_revoke_returns_204(): void
    {
        $this->authz->method('requirePermission');
        $sdkKey = $this->buildSdkKey('key-1');
        $this->sdkKeyStorage->method('findById')->willReturn($sdkKey);
        $this->sdkKeyService->expects($this->once())->method('revoke')->with('key-1');

        $response = $this->controller->revoke('key-1');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function test_rotate_returns_201_with_new_raw_key(): void
    {
        $this->authz->method('requirePermission');
        $sdkKey    = $this->buildSdkKey('old-key');
        $newSdkKey = $this->buildSdkKey('new-key');
        $this->sdkKeyStorage->method('findById')->willReturn($sdkKey);
        $this->sdkKeyService->method('rotate')->willReturn([
            'rawKey' => 'vff_srv_newrawkey123456789012345',
            'sdkKey' => $newSdkKey,
        ]);

        $response = $this->controller->rotate('old-key');
        $body     = $this->decode($response);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertArrayHasKey('rawKey', $body['data']);
    }

    public function test_list_old_key_shows_rotating_to_field(): void
    {
        $this->authz->method('requirePermission');

        $sdkKey = new SdkKey(
            id: 'old-id', name: 'old', keyPrefix: 'abc123456789',
            keyHash: hash('sha256', 'x'), kind: SdkKey::KIND_SERVER,
            projectId: 'p', environment: 'e',
            createdAt: new \DateTimeImmutable(), createdBy: 'u',
            successorKeyId: 'new-id',
        );

        $this->sdkKeyStorage->method('findByProjectAndEnv')->willReturn([$sdkKey]);
        $request  = Request::create('/api/management/v1/keys?projectId=p&environment=e', 'GET');
        $response = $this->controller->list($request);
        $body     = $this->decode($response);

        $this->assertSame('new-id', $body['data'][0]['rotatingTo']);
    }

    public function test_issue_requires_flags_admin_any(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->issue(new Request());
    }

    private function buildSdkKey(string $id): SdkKey
    {
        return new SdkKey(
            id: $id, name: 'test-key', keyPrefix: 'abc123456789',
            keyHash: hash('sha256', 'x'), kind: SdkKey::KIND_SERVER,
            projectId: 'p', environment: 'e',
            createdAt: new \DateTimeImmutable(), createdBy: 'u',
        );
    }

    private function jsonRequest(array $data): Request
    {
        return Request::create('/api/management/v1/keys', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
    }
}
