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
use Vortos\FeatureFlags\FlagKind;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\KillSwitchController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class KillSwitchControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagStorageInterface $storage;
    private KillSwitchController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->storage = $this->createMock(FlagStorageInterface::class);

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

        $this->controller = new KillSwitchController(
            renderer: $this->renderer,
            storage: $this->storage,
            writeService: $writeService,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
            scopeContext: new FlagScopeContext(),
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_index_requires_killswitch_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->index(new Request());
    }

    public function test_index_only_shows_ops_flags(): void
    {
        $this->authz->method('requirePermission');

        $opsFlag = $this->buildFlag('kill-feature', true, FlagKind::Ops);
        $releaseFlag = $this->buildFlag('feature-x', true, FlagKind::Release);
        $this->storage->method('findAll')->willReturn([$opsFlag, $releaseFlag]);

        $this->renderer->method('render')
            ->with('kill_switch/index.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 1
                    && $ctx['flags'][0]->name === 'kill-feature';
            }))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_toggle_requires_killswitch_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->toggle(Request::create('', 'POST'), 'test');
    }

    public function test_toggle_rejects_non_ops_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(
            $this->buildFlag('feature-x', true, FlagKind::Release),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('ops/kill-switch');
        $this->controller->toggle(Request::create('', 'POST'), 'feature-x');
    }

    public function test_toggle_returns_404_for_missing_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->toggle(Request::create('', 'POST'), 'nonexistent');
    }

    public function test_toggle_disables_enabled_ops_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(
            $this->buildFlag('kill-feature', true, FlagKind::Ops),
        );
        $this->storage->method('save');

        $response = $this->controller->toggle(Request::create('', 'POST'), 'kill-feature');
        $this->assertSame(302, $response->getStatusCode());
    }

    private function buildFlag(string $name, bool $enabled, FlagKind $kind = FlagKind::Release): FeatureFlag
    {
        return FeatureFlag::fromArray([
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => $name,
            'description' => 'test',
            'enabled' => $enabled,
            'rules' => [],
            'kind' => $kind->value,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
