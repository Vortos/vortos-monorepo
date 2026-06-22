<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\SegmentController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class SegmentControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private SegmentStorageInterface $segmentStorage;
    private SegmentController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->segmentStorage = $this->createMock(SegmentStorageInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $this->renderer = $this->createMock(TwigRenderer::class);

        $this->controller = new SegmentController(
            renderer: $this->renderer,
            segmentStorage: $this->segmentStorage,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
        );
    }

    public function test_index_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->index(new Request());
    }

    public function test_index_renders_segments_list(): void
    {
        $this->authz->method('requirePermission');
        $this->segmentStorage->method('findAll')->willReturn([]);

        $this->renderer->method('render')
            ->with('segments/index.html.twig', $this->callback(fn($ctx) => $ctx['active_nav'] === 'segments'))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_detail_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->detail(new Request(), 'seg-1');
    }

    public function test_detail_returns_404_for_missing_segment(): void
    {
        $this->authz->method('requirePermission');
        $this->segmentStorage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->detail(new Request(), 'nonexistent');
    }

    public function test_detail_renders_segment(): void
    {
        $this->authz->method('requirePermission');

        $segment = new Segment(
            id: 'seg-1',
            name: 'beta-users',
            description: 'Beta test users',
            rules: [],
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );
        $this->segmentStorage->method('findByName')->willReturn($segment);

        $this->renderer->method('render')
            ->with('segments/detail.html.twig', $this->callback(fn($ctx) => $ctx['segment']->name === 'beta-users'))
            ->willReturn(new Response(''));

        $this->controller->detail(new Request(), 'beta-users');
    }
}
