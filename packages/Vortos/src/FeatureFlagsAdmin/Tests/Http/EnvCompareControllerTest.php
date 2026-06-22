<?php

declare(strict_types=1);

namespace Vortos\FeatureFlagsAdmin\Tests\Http;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Http\RateLimit\FlagRateLimitService;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;
use Vortos\FeatureFlagsAdmin\Http\Controller\EnvCompareController;
use Vortos\FeatureFlagsAdmin\Rendering\TwigRenderer;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Request;
use Vortos\Http\Response;

final class EnvCompareControllerTest extends TestCase
{
    private ManagementAuthzGateInterface $authz;
    private FlagStateViewRepositoryInterface $stateView;
    private EnvCompareController $controller;
    private TwigRenderer $renderer;

    protected function setUp(): void
    {
        $this->authz = $this->createMock(ManagementAuthzGateInterface::class);
        $this->stateView = $this->createMock(FlagStateViewRepositoryInterface::class);

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('admin-1', ['ROLE_ADMIN']));

        $this->renderer = $this->createMock(TwigRenderer::class);

        $this->controller = new EnvCompareController(
            renderer: $this->renderer,
            stateView: $this->stateView,
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

    public function test_compare_detects_same_flags(): void
    {
        $this->authz->method('requirePermission');

        $flagA = $this->buildStateView('my-flag', true, 'staging', 2);
        $flagB = $this->buildStateView('my-flag', true, 'production', 2);

        $this->stateView->method('all')
            ->willReturnCallback(fn(string $env) => match($env) {
                'staging' => [$flagA],
                'production' => [$flagB],
                default => [],
            });

        $this->renderer->method('render')
            ->with('env_compare/index.html.twig', $this->callback(function (array $ctx) {
                return count($ctx['comparisons']) === 1
                    && $ctx['comparisons'][0]['status'] === 'same';
            }))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_compare_detects_different_flags(): void
    {
        $this->authz->method('requirePermission');

        $flagA = $this->buildStateView('my-flag', true, 'staging', 2);
        $flagB = $this->buildStateView('my-flag', false, 'production', 2);

        $this->stateView->method('all')
            ->willReturnCallback(fn(string $env) => match($env) {
                'staging' => [$flagA],
                'production' => [$flagB],
                default => [],
            });

        $this->renderer->method('render')
            ->with('env_compare/index.html.twig', $this->callback(function (array $ctx) {
                return $ctx['comparisons'][0]['status'] === 'different';
            }))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_compare_detects_only_in_one_env(): void
    {
        $this->authz->method('requirePermission');

        $flagA = $this->buildStateView('staging-only', true, 'staging');

        $this->stateView->method('all')
            ->willReturnCallback(fn(string $env) => match($env) {
                'staging' => [$flagA],
                'production' => [],
                default => [],
            });

        $this->renderer->method('render')
            ->with('env_compare/index.html.twig', $this->callback(function (array $ctx) {
                return $ctx['comparisons'][0]['status'] === 'only_a';
            }))
            ->willReturn(new Response(''));

        $this->controller->index(new Request());
    }

    public function test_htmx_request_returns_fragment(): void
    {
        $this->authz->method('requirePermission');
        $this->stateView->method('all')->willReturn([]);

        $this->renderer->method('renderFragment')
            ->with('env_compare/_compare_table.html.twig', $this->anything())
            ->willReturn(new Response('fragment'));

        $request = Request::create('/admin/flags/env-compare');
        $request->headers->set('HX-Request', 'true');

        $response = $this->controller->index($request);
        $this->assertSame('fragment', $response->getContent());
    }

    private function buildStateView(string $name, bool $enabled, string $env = 'production', int $rules = 0): FlagStateView
    {
        return new FlagStateView(
            flagName: $name, flagId: 'id-' . $name, enabled: $enabled, archived: false,
            valueType: 'bool', kind: 'release', ruleCount: $rules, variants: null,
            scheduled: false, lastEventType: '', lastActorId: '', updatedAt: '',
            environment: $env,
        );
    }
}
