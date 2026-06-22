<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Explain\EvaluationExplainer;
use Vortos\FeatureFlags\Authz\NullFlagAuthzGate;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\FlagDetailController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\NotFoundException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class FlagDetailControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagStorageInterface $storage;
    private FlagDetailController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->storage = $this->createMock(FlagStorageInterface::class);
        $stateView = $this->createMock(FlagStateViewRepositoryInterface::class);
        $stateView->method('findByName')->willReturn(null);
        $auditLog = $this->createMock(FlagAuditLogRepositoryInterface::class);
        $auditLog->method('findByFlag')->willReturn([]);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $this->renderer = $this->createMock(TwigRenderer::class);
        $explainer = new EvaluationExplainer();

        $this->controller = new FlagDetailController(
            renderer: $this->renderer,
            storage: $this->storage,
            stateView: $stateView,
            auditLog: $auditLog,
            authz: $this->authz,
            currentUser: new CurrentUserProvider($adapter),
            rateLimit: $this->createMock(FlagRateLimitService::class),
            scopeContext: new FlagScopeContext(),
            projectContext: new ProjectContext(),
            explainer: $explainer,
        );
    }

    public function test_show_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->show(new Request(), 'my-flag');
    }

    public function test_show_returns_404_for_missing_flag(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->controller->show(new Request(), 'nonexistent');
    }

    public function test_show_renders_flag_detail(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('dark-mode'));

        $this->renderer->method('render')
            ->with('flags/detail.html.twig', $this->callback(function (array $ctx) {
                return $ctx['flag']->name === 'dark-mode'
                    && $ctx['active_nav'] === 'dashboard';
            }))
            ->willReturn(new Response(''));

        $this->controller->show(new Request(), 'dark-mode');
    }

    public function test_show_respects_environment_param(): void
    {
        $this->authz->method('requirePermission');
        $this->storage->method('findByName')->willReturn($this->buildFlag('my-flag'));

        $this->renderer->method('render')
            ->with('flags/detail.html.twig', $this->callback(function (array $ctx) {
                return $ctx['env'] === 'staging';
            }))
            ->willReturn(new Response(''));

        $request = Request::create('/admin/flags/detail/my-flag', 'GET', ['env' => 'staging']);
        $this->controller->show($request, 'my-flag');
    }

    private function buildFlag(string $name): FeatureFlag
    {
        return FeatureFlag::fromArray([
            'id' => 'id-' . $name,
            'name' => $name,
            'description' => 'test',
            'enabled' => true,
            'rules' => [],
            'created_at' => '2026-01-01T00:00:00+00:00',
            'updated_at' => '2026-01-01T00:00:00+00:00',
        ]);
    }
}
