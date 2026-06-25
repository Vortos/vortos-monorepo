<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Quota;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\Middleware\QuotaMiddleware;
use Vortos\Auth\Quota\QuotaConsumeResult;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Quota\QuotaPeriod;
use Vortos\Auth\Quota\QuotaRule;
use Vortos\Auth\Quota\QuotaSubjectProvenance;
use Vortos\Auth\Quota\Resolver\GlobalQuotaResolver;
use Vortos\Auth\Quota\Resolver\UserQuotaResolver;
use Vortos\Auth\Quota\Contract\QuotaStoreInterface;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;

final class QuotaMiddlewareSecurityTest extends TestCase
{
    private function makeMiddleware(
        UserIdentityInterface $identity,
        array $routeMap,
        array $resolvers,
        array $policies = [],
        bool $compensateOnServerError = true,
        ?QuotaStoreInterface $store = null,
    ): QuotaMiddleware {
        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', $identity);
        $provider = new CurrentUserProvider($adapter);

        $store ??= $this->createMock(QuotaStoreInterface::class);

        return new QuotaMiddleware(
            $provider,
            $store,
            $routeMap,
            $policies,
            $resolvers,
            QuotaFailureMode::FailClosed,
            false,
            true,
            $compensateOnServerError,
        );
    }

    private function request(string $controller = 'App\\Controller\\TestController'): Request
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        return $request;
    }

    // --- #20: Anonymous users should not bypass GlobalQuotaResolver ---

    public function test_global_quota_enforced_for_anonymous_users(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())
            ->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new AnonymousIdentity(),
            ['App\\Controller\\TestController' => [['quota' => 'requests', 'cost' => 1, 'by' => GlobalQuotaResolver::class]]],
            [GlobalQuotaResolver::class => new GlobalQuotaResolver()],
            [GlobalQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_user_quota_skipped_for_anonymous_users(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->never())->method('consume');

        $middleware = $this->makeMiddleware(
            new AnonymousIdentity(),
            ['App\\Controller\\TestController' => [['quota' => 'api_calls', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_mixed_resolvers_anonymous_user_only_global_enforced(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())
            ->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 99, time() + 3600));

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(100, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new AnonymousIdentity(),
            ['App\\Controller\\TestController' => [
                ['quota' => 'api_calls', 'cost' => 1, 'by' => UserQuotaResolver::class],
                ['quota' => 'global_requests', 'cost' => 1, 'by' => GlobalQuotaResolver::class],
            ]],
            [
                UserQuotaResolver::class => new UserQuotaResolver(),
                GlobalQuotaResolver::class => new GlobalQuotaResolver(),
            ],
            [GlobalQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(200, $response->getStatusCode());
    }

    // --- #21: Quota compensation on server error ---

    public function test_quota_compensated_on_500_response(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));
        $store->expects($this->once())->method('compensate');

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], []),
            ['App\\Controller\\TestController' => [['quota' => 'exports', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [UserQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['error' => 'internal'], 500));
        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_quota_not_compensated_on_200_response(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));
        $store->expects($this->never())->method('compensate');

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], []),
            ['App\\Controller\\TestController' => [['quota' => 'exports', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [UserQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_quota_not_compensated_on_4xx_response(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));
        $store->expects($this->never())->method('compensate');

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], []),
            ['App\\Controller\\TestController' => [['quota' => 'exports', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [UserQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['error' => 'bad request'], 400));
        $this->assertSame(400, $response->getStatusCode());
    }

    public function test_compensation_disabled_via_config(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));
        $store->expects($this->never())->method('compensate');

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], []),
            ['App\\Controller\\TestController' => [['quota' => 'exports', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [UserQuotaResolver::class => $policy],
            compensateOnServerError: false,
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['error' => 'internal'], 500));
        $this->assertSame(500, $response->getStatusCode());
    }

    // --- #22: Provenance rejection ---

    public function test_claim_derived_resolver_rejected_at_runtime(): void
    {
        $unsafeResolver = new class implements QuotaSubjectResolverInterface {
            public function bucket(): string { return 'org'; }
            public function resolve(UserIdentityInterface $identity): ?string { return $identity->getAttribute('org_id'); }
            public function requiresAuthentication(): bool { return true; }
            public function provenance(): QuotaSubjectProvenance { return QuotaSubjectProvenance::ClaimDerived; }
        };

        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->never())->method('consume');

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], ['org_id' => 'org-evil']),
            ['App\\Controller\\TestController' => [['quota' => 'org_ops', 'cost' => 1, 'by' => 'UnsafeResolver']]],
            ['UnsafeResolver' => $unsafeResolver],
            [],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_server_verified_resolver_accepted(): void
    {
        $store = $this->createMock(QuotaStoreInterface::class);
        $store->expects($this->once())->method('consume')
            ->willReturn(new QuotaConsumeResult(true, 1, 9, time() + 3600));

        $policy = $this->createConfiguredMock(QuotaPolicyInterface::class, [
            'getQuota' => new QuotaRule(10, QuotaPeriod::Hourly),
        ]);

        $middleware = $this->makeMiddleware(
            new UserIdentity('user-1', ['ROLE_USER'], []),
            ['App\\Controller\\TestController' => [['quota' => 'api', 'cost' => 1, 'by' => UserQuotaResolver::class]]],
            [UserQuotaResolver::class => new UserQuotaResolver()],
            [UserQuotaResolver::class => $policy],
            store: $store,
        );

        $response = $middleware->handle($this->request(), fn() => new JsonResponse(['ok' => true], 200));
        $this->assertSame(200, $response->getStatusCode());
    }
}
