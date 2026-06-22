<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\DashboardController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class DashboardControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagStateViewRepositoryInterface $stateView;
    private DashboardController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->stateView = $this->createMock(FlagStateViewRepositoryInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));
        $currentUser = new CurrentUserProvider($adapter);

        $rateLimit = $this->createMock(FlagRateLimitService::class);

        $this->renderer = $this->createMock(TwigRenderer::class);

        $this->controller = new DashboardController(
            renderer: $this->renderer,
            stateView: $this->stateView,
            authz: $this->authz,
            currentUser: $currentUser,
            rateLimit: $rateLimit,
            scopeContext: new FlagScopeContext(),
            projectContext: new ProjectContext(),
        );
    }

    public function test_index_requires_permission(): void
    {
        $this->authz->method('requirePermission')
            ->willThrowException(new ForbiddenException());

        $this->expectException(ForbiddenException::class);
        $this->controller->index(new Request());
    }

    public function test_index_returns_rendered_view(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([
            $this->buildStateView('my-flag', true),
        ]);

        $expected = new Response('<html>dashboard</html>');
        $this->renderer->method('render')
            ->with('flags/dashboard.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 1
                    && $ctx['env'] === 'production'
                    && $ctx['active_nav'] === 'dashboard';
            }))
            ->willReturn($expected);

        $response = $this->controller->index(new Request());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_index_filters_by_search(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([
            $this->buildStateView('dark-mode', true),
            $this->buildStateView('new-checkout', false),
        ]);

        $this->renderer->method('render')
            ->with('flags/dashboard.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 1
                    && $ctx['flags'][0]->flagName === 'dark-mode';
            }))
            ->willReturn(new Response(''));

        $request = Request::create('/admin/flags', 'GET', ['q' => 'dark']);
        $this->controller->index($request);
    }

    public function test_index_filters_by_kind(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([
            $this->buildStateView('my-flag', true, 'release'),
            $this->buildStateView('kill-switch', true, 'ops'),
        ]);

        $this->renderer->method('render')
            ->with('flags/dashboard.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 1
                    && $ctx['flags'][0]->kind === 'ops';
            }))
            ->willReturn(new Response(''));

        $request = Request::create('/admin/flags', 'GET', ['kind' => 'ops']);
        $this->controller->index($request);
    }

    public function test_index_filters_by_status_enabled(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([
            $this->buildStateView('enabled-flag', true),
            $this->buildStateView('disabled-flag', false),
        ]);

        $this->renderer->method('render')
            ->with('flags/dashboard.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 1
                    && $ctx['flags'][0]->flagName === 'enabled-flag';
            }))
            ->willReturn(new Response(''));

        $request = Request::create('/admin/flags', 'GET', ['status' => 'enabled']);
        $this->controller->index($request);
    }

    public function test_index_returns_fragment_for_htmx_request(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([]);

        $expected = new Response('<table>fragment</table>');
        $this->renderer->method('renderFragment')
            ->with('flags/_flag_table.html.twig', $this->anything())
            ->willReturn($expected);

        $request = Request::create('/admin/flags');
        $request->headers->set('HX-Request', 'true');
        $response = $this->controller->index($request);

        $this->assertSame($expected, $response);
    }

    public function test_index_pagination(): void
    {
        $this->authz->method('requirePermission');
        $flags = [];
        for ($i = 0; $i < 60; $i++) {
            $flags[] = $this->buildStateView("flag-{$i}", true);
        }
        $this->stateView->method('all')->willReturn($flags);

        $this->renderer->method('render')
            ->with('flags/dashboard.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['flags']) === 10
                    && $ctx['page'] === 2
                    && $ctx['total_pages'] === 2
                    && $ctx['total'] === 60;
            }))
            ->willReturn(new Response(''));

        $request = Request::create('/admin/flags', 'GET', ['page' => '2']);
        $this->controller->index($request);
    }

    public function test_index_with_environment(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->expects($this->once())
            ->method('all')
            ->with('staging', 1000)
            ->willReturn([]);

        $this->renderer->method('render')->willReturn(new Response(''));

        $request = Request::create('/admin/flags', 'GET', ['env' => 'staging']);
        $this->controller->index($request);
    }

    private function buildStateView(string $name, bool $enabled, string $kind = 'release'): FlagStateView
    {
        return new FlagStateView(
            flagName: $name,
            flagId: 'id-' . $name,
            enabled: $enabled,
            archived: false,
            valueType: 'bool',
            kind: $kind,
            ruleCount: 0,
            variants: null,
            scheduled: false,
            lastEventType: 'FlagCreated',
            lastActorId: 'admin',
            updatedAt: '2026-06-22T00:00:00',
        );
    }
}
