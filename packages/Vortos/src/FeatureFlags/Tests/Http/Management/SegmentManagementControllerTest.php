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
use Vortos\FeatureFlags\Http\Management\SegmentManagementController;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;

final class SegmentManagementControllerTest extends TestCase
{
    private SegmentStorageInterface $storage;
    private ManagementAuthzGateInterface $authz;
    private FlagRateLimitService $rateLimit;
    private CurrentUserProvider $currentUser;
    private SegmentManagementController $controller;

    protected function setUp(): void
    {
        $this->storage     = $this->createMock(SegmentStorageInterface::class);
        $this->authz       = $this->createMock(ManagementAuthzGateInterface::class);
        $this->rateLimit   = $this->createMock(FlagRateLimitService::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user1', ['ROLE_ADMIN']));
        $this->currentUser = new CurrentUserProvider($adapter);

        $validator = new VortosValidator(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator());

        $this->controller = new SegmentManagementController(
            storage:    $this->storage,
            authz:      $this->authz,
            rateLimit:  $this->rateLimit,
            response:   new ManagementResponseFactory(),
            currentUser: $this->currentUser,
            validator:  $validator,
        );
    }

    public function test_list_returns_segments(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findAll')->willReturn([$this->buildSegment('beta-users')]);

        $response = $this->controller->list();
        $body     = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $body['data']);
        $this->assertSame('beta-users', $body['data'][0]['name']);
    }

    public function test_list_requires_read_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->list();
    }

    public function test_create_returns_201(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->expects($this->once())->method('save');

        $request  = $this->jsonRequest(['name' => 'new-segment', 'description' => 'desc']);
        $response = $this->controller->create($request);

        $this->assertSame(201, $response->getStatusCode());
    }

    public function test_show_returns_404_for_missing_segment(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->show('nonexistent');
    }

    public function test_delete_returns_204(): void
    {
        $this->authz->method('requirePermission');
        $segment = $this->buildSegment('my-seg');
        $this->storage->method('findByName')->willReturn($segment);
        $this->storage->expects($this->once())->method('delete')->with('my-seg');

        $response = $this->controller->delete('my-seg');
        $this->assertSame(204, $response->getStatusCode());
    }

    private function buildSegment(string $name): Segment
    {
        $now = new \DateTimeImmutable();
        return new Segment(
            id: 'seg-' . $name, name: $name, description: '',
            rules: [], createdAt: $now, updatedAt: $now,
        );
    }

    private function jsonRequest(array $data): Request
    {
        return Request::create('/api/management/v1/segments', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($data));
    }

    private function decode(object $response): array
    {
        return json_decode($response->getContent(), true);
    }
}
