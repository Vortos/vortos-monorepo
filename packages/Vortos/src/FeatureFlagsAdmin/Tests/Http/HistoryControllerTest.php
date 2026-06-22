<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\Management\Interceptor\ChangeRequestInterceptorInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\HistoryController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class HistoryControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagAuditLogRepositoryInterface $auditLog;
    private FlagStorageInterface $storage;
    private HistoryController $controller;
    private TwigRenderer $renderer;
    private ChangeRequestInterceptorInterface $interceptor;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->auditLog = $this->createMock(FlagAuditLogRepositoryInterface::class);
        $this->storage = $this->createMock(FlagStorageInterface::class);
        $this->interceptor = $this->createMock(ChangeRequestInterceptorInterface::class);

        $uow = $this->createMock(UnitOfWorkInterface::class);
        $uow->method('run')->willReturnCallback(static fn(callable $w) => $w());
        $eventBus = $this->createMock(EventBusInterface::class);
        $envStateStorage = $this->createMock(FlagEnvironmentStateStorageInterface::class);
        $envStateStorage->method('findForFlag')->willReturn(null);

        $writeService = new FlagWriteService(
            storage: $this->storage,
            unitOfWork: $uow,
            eventBus: $eventBus,
            envStateStorage: $envStateStorage,
        );

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $this->renderer = $this->createMock(TwigRenderer::class);

        $this->controller = new HistoryController(
            renderer: $this->renderer,
            auditLog: $this->auditLog,
            storage: $this->storage,
            writeService: $writeService,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
            changeRequestInterceptor: $this->interceptor,
            scopeContext: new FlagScopeContext(),
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

    public function test_index_renders_history_page(): void
    {
        $this->authz->method('requirePermission');
        $this->renderer->method('render')
            ->with('history/index.html.twig', $this->callback(fn($ctx) => $ctx['active_nav'] === 'history'))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_index_with_flag_filter(): void
    {
        $this->authz->method('requirePermission');
        $this->auditLog->expects($this->once())
            ->method('findByFlag')
            ->with('my-flag', 50)
            ->willReturn([]);

        $this->renderer->method('render')->willReturn(new Response(''));

        $request = Request::create('/admin/flags/history', 'GET', ['flag' => 'my-flag']);
        $this->controller->index($request);
    }

    public function test_revert_requires_write_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->revert(Request::create('', 'POST'), 'test', 'event-1');
    }

    public function test_revert_rejects_short_reason(): void
    {
        $this->authz->method('requirePermission');

        $request = Request::create('', 'POST', ['reason' => 'short']);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('reason');
        $this->controller->revert($request, 'test', 'event-1');
    }

    public function test_revert_blocked_for_protected_environment(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(true);

        $request = Request::create('', 'POST', ['reason' => 'This is a long enough reason for revert']);
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('change request');
        $this->controller->revert($request, 'test', 'event-1');
    }

    public function test_revert_returns_404_for_missing_event(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $this->auditLog->method('findByFlag')->willReturn([]);

        $request = Request::create('', 'POST', ['reason' => 'This is a long enough reason for revert']);
        $this->expectException(NotFoundException::class);
        $this->controller->revert($request, 'test', 'nonexistent');
    }

    public function test_diff_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->diff(new Request(), 'test', 'a', 'b');
    }

    public function test_diff_returns_404_for_missing_entries(): void
    {
        $this->authz->method('requirePermission');
        $this->auditLog->method('findByFlag')->willReturn([]);

        $this->expectException(NotFoundException::class);
        $this->controller->diff(new Request(), 'test', 'a', 'b');
    }
}
