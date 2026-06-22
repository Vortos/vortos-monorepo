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
use Vortos\FeatureFlags\Http\Management\Interceptor\NullChangeRequestInterceptor;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Validation\FlagValidator;
use Vortos\FeatureFlagsAdmin\Http\Fragment\FlagFragmentController;
use Vortos\FeatureFlags\Storage\FlagStorageInterface as ValidatorStorage;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

final class FlagFragmentControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagStorageInterface $storage;
    private FlagFragmentController $controller;
    private TwigRenderer $renderer;
    private ChangeRequestInterceptorInterface $interceptor;

    protected function setUp(): void
    {
        DomainEventLedger::discard();

        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->storage = $this->createMock(FlagStorageInterface::class);
        $this->interceptor = $this->createMock(ChangeRequestInterceptorInterface::class);

        $stateView = $this->createMock(FlagStateViewRepositoryInterface::class);
        $stateView->method('findByName')->willReturn(new FlagStateView(
            flagName: 'test', flagId: 'id', enabled: true, archived: false,
            valueType: 'bool', kind: 'release', ruleCount: 0, variants: null,
            scheduled: false, lastEventType: '', lastActorId: '', updatedAt: '',
        ));

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
        $this->renderer->method('renderFragment')->willReturn(new Response('fragment'));

        $this->controller = new FlagFragmentController(
            renderer: $this->renderer,
            storage: $this->storage,
            stateView: $stateView,
            writeService: $writeService,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
            validator: new FlagValidator($this->storage),
            scopeContext: new FlagScopeContext(),
            projectContext: new ProjectContext(),
            changeRequestInterceptor: $this->interceptor,
        );
    }

    protected function tearDown(): void
    {
        DomainEventLedger::discard();
    }

    public function test_toggle_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->toggle(Request::create('/admin/flags/fragment/test/toggle', 'POST'), 'test');
    }

    public function test_toggle_rejects_protected_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(true);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('change request');
        $this->controller->toggle(Request::create('/admin/flags/fragment/test/toggle', 'POST'), 'test');
    }

    public function test_toggle_returns_404_for_missing_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->toggle(Request::create('/admin/flags/fragment/test/toggle', 'POST'), 'test');
    }

    public function test_toggle_disables_enabled_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $flag = $this->buildFlag('test', true);
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $response = $this->controller->toggle(
            Request::create('/admin/flags/fragment/test/toggle', 'POST'),
            'test',
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_toggle_enables_disabled_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $flag = $this->buildFlag('test', false);
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $response = $this->controller->toggle(
            Request::create('/admin/flags/fragment/test/toggle', 'POST'),
            'test',
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_rollout_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->rollout(Request::create('/admin/flags/fragment/test/rollout', 'POST'), 'test');
    }

    public function test_rollout_clamps_percentage_to_0_100(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $flag = $this->buildFlag('test', true);
        $this->storage->method('findByName')->willReturn($flag);
        $this->storage->method('save');

        $request = Request::create('/admin/flags/fragment/test/rollout', 'POST', ['percentage' => '150']);
        $response = $this->controller->rollout($request, 'test');
        $this->assertSame(200, $response->getStatusCode());

        $request2 = Request::create('/admin/flags/fragment/test/rollout', 'POST', ['percentage' => '-10']);
        $response2 = $this->controller->rollout($request2, 'test');
        $this->assertSame(200, $response2->getStatusCode());
    }

    public function test_update_rules_rejects_invalid_json(): void
    {
        $this->authz->method('requirePermission');
        $this->interceptor->method('isProtected')->willReturn(false);
        $this->storage->method('findByName')->willReturn($this->buildFlag('test', true));

        $request = Request::create('/admin/flags/fragment/test/rules', 'PUT', [], [], [], [], 'not-json');
        $response = $this->controller->updateRules($request, 'test');

        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_create_form_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->createForm(Request::create('/admin/flags/fragment/create-form', 'GET'));
    }

    public function test_create_form_renders_template(): void
    {
        $this->authz->method('requirePermission');

        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('flags/_create_form.html.twig', $this->arrayHasKey('env'));

        $this->controller->createForm(
            Request::create('/admin/flags/fragment/create-form', 'GET', ['env' => 'staging']),
        );
    }

    public function test_create_rejects_invalid_name(): void
    {
        $this->authz->method('requirePermission');

        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('flags/_create_form.html.twig', $this->arrayHasKey('error'));

        $this->controller->create(
            Request::create('/admin/flags/fragment/create', 'POST', ['name' => 'Invalid Name!']),
        );
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('existing-flag', true));

        $this->renderer->expects($this->once())
            ->method('renderFragment')
            ->with('flags/_create_form.html.twig', $this->arrayHasKey('error'));

        $this->controller->create(
            Request::create('/admin/flags/fragment/create', 'POST', ['name' => 'existing-flag']),
        );
    }

    public function test_create_returns_redirect_on_success(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);
        $this->storage->method('save');

        $response = $this->controller->create(
            Request::create('/admin/flags/fragment/create', 'POST', [
                'name'        => 'new-flag',
                'description' => 'test',
                'kind'        => 'release',
                'value_type'  => 'bool',
                'env'         => 'production',
            ]),
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertStringContainsString('new-flag', $response->headers->get('HX-Redirect'));
    }

    public function test_delete_returns_404_for_missing_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->delete(
            Request::create('/admin/flags/fragment/my-flag', 'DELETE'),
            'my-flag',
        );
    }

    public function test_delete_archives_and_redirects(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('my-flag', true));
        $this->storage->method('save');

        $response = $this->controller->delete(
            Request::create('/admin/flags/fragment/my-flag', 'DELETE'),
            'my-flag',
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('/admin/flags', $response->headers->get('HX-Redirect'));
    }

    private function buildFlag(string $name, bool $enabled): FeatureFlag
    {
        return FeatureFlag::fromArray([
            'id' => '11111111-1111-4111-8111-111111111111',
            'name' => $name,
            'description' => 'test',
            'enabled' => $enabled,
            'rules' => [],
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
