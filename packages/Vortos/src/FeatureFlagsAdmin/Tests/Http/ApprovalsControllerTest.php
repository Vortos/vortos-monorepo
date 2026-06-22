<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestPolicy;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestService;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\Storage\EnvironmentProtectionStorageInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\ApprovalsController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class ApprovalsControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private ChangeRequestStorageInterface $changeRequestStorage;
    private ApprovalsController $controller;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->changeRequestStorage = $this->createMock(ChangeRequestStorageInterface::class);

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());
        $eventBus = $this->createMock(EventBusInterface::class);

        $flagStorage = $this->createMock(FlagStorageInterface::class);
        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);

        $writeService = new FlagWriteService(
            storage: $flagStorage,
            unitOfWork: $uow,
            eventBus: $eventBus,
            envStateStorage: $envStateStorage,
        );

        $promotionService = new FlagPromotionService(
            storage: $flagStorage,
            envStateStorage: $envStateStorage,
            unitOfWork: $uow,
            eventBus: $eventBus,
        );

        $protectionStorage = $this->createMock(EnvironmentProtectionStorageInterface::class);
        $protectionStorage->method('findForEnvironment')->willReturn(null);

        $policy = new ChangeRequestPolicy($protectionStorage);

        $changeRequestService = new ChangeRequestService(
            storage: $this->changeRequestStorage,
            policy: $policy,
            writeService: $writeService,
            promotionService: $promotionService,
            unitOfWork: $uow,
            eventBus: $eventBus,
            scopeContext: new FlagScopeContext(),
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $renderer = $this->createMock(TwigRenderer::class);
        $renderer->method('render')->willReturn(new Response(''));
        $renderer->method('renderFragment')->willReturn(new Response(''));

        $this->controller = new ApprovalsController(
            renderer: $renderer,
            changeRequestService: $changeRequestService,
            changeRequestStorage: $this->changeRequestStorage,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_index_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->index(new Request());
    }

    public function test_index_renders_approvals_page(): void
    {
        $this->authz->method('requirePermission');
        $this->changeRequestStorage->method('findByFlag')->willReturn([]);

        $response = $this->controller->index(new Request());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_approve_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->approve(Request::create('', 'POST', ['reason' => 'test reason long enough']), 'id-1');
    }

    public function test_approve_rejects_short_reason(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('reason');
        $this->controller->approve(Request::create('', 'POST', ['reason' => 'short']), 'id-1');
    }

    public function test_approve_returns_404_for_missing_request(): void
    {
        $this->authz->method('requirePermission');
        $this->changeRequestStorage->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->approve(
            Request::create('', 'POST', ['reason' => 'This is long enough reason']),
            'nonexistent',
        );
    }

    public function test_reject_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->reject(Request::create('', 'POST', ['reason' => 'test reason long enough']), 'id-1');
    }

    public function test_reject_rejects_short_reason(): void
    {
        $this->authz->method('requirePermission');

        $this->expectException(ForbiddenException::class);
        $this->controller->reject(Request::create('', 'POST', ['reason' => 'no']), 'id-1');
    }
}
