<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\FeatureAccess;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessDecision;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessPolicyInterface;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter as CacheArrayAdapter;

final class AlwaysDenyPolicy implements FeatureAccessPolicyInterface
{
    public function evaluate(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): FeatureAccessDecision
    {
        return FeatureAccessDecision::Forbidden;
    }
}

final class AlwaysAllowPolicy implements FeatureAccessPolicyInterface
{
    public function evaluate(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): FeatureAccessDecision
    {
        return FeatureAccessDecision::Allowed;
    }
}

final class PlanBasedPolicy implements FeatureAccessPolicyInterface
{
    public function evaluate(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): FeatureAccessDecision
    {
        $plan = $identity->getAttribute('plan', 'free');
        if ($feature !== 'api.bulk_export') {
            return FeatureAccessDecision::Allowed;
        }
        return $plan === 'pro' ? FeatureAccessDecision::Allowed : FeatureAccessDecision::Forbidden;
    }
}

/**
 * Decides 402 vs 403 at request time from identity state — the case the old
 * per-route paymentRequired bool could not express.
 */
final class SubscriptionPolicy implements FeatureAccessPolicyInterface
{
    public function evaluate(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): FeatureAccessDecision
    {
        if ($identity->getAttribute('plan', 'free') !== 'pro') {
            return FeatureAccessDecision::Forbidden;            // never included → 403
        }
        return $identity->getAttribute('subscription_active', true)
            ? FeatureAccessDecision::Allowed
            : FeatureAccessDecision::PaymentRequired;           // lapsed → 402
    }
}

final class FeatureAccessMiddlewareTest extends TestCase
{
    private function makeProvider(bool $authenticated = true, array $attributes = []): CurrentUserProvider
    {
        $adapter = new CacheArrayAdapter();
        $identity = $authenticated
            ? new UserIdentity('user-1', ['ROLE_USER'], $attributes)
            : new AnonymousIdentity();
        $adapter->set('auth:identity', $identity);
        return new CurrentUserProvider($adapter);
    }

    private function makeRequest(string $controller): Request
    {
        $request = Request::create('/test');
        $request->attributes->set('_controller', $controller);
        return $request;
    }

    private function next(): \Closure
    {
        return fn(Request $r) => new Response('ok', 200);
    }

    public function test_allows_when_no_route_map(): void
    {
        $middleware = new FeatureAccessMiddleware($this->makeProvider(), [], []);
        $response = $middleware->handle($this->makeRequest('App\Controller\TestController'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_when_policy_grants(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.basic']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro']),
            $routeMap,
            [new AlwaysAllowPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_denies_with_403_when_policy_denies(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [new AlwaysDenyPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_returns_402_when_subscription_lapsed(): void
    {
        // Same route, same feature — the policy returns PaymentRequired from
        // identity state. Impossible with the old per-route paymentRequired bool.
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro', 'subscription_active' => false]),
            $routeMap,
            [new SubscriptionPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(402, $response->getStatusCode());
    }

    public function test_returns_403_when_plan_excludes_feature(): void
    {
        // Same route/feature/policy as the 402 case, only identity differs.
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'free']),
            $routeMap,
            [new SubscriptionPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_forbidden_wins_over_payment_required_across_policies(): void
    {
        // One policy says 402, another says 403 — the more restrictive denial wins.
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $payment = new class implements FeatureAccessPolicyInterface {
            public function evaluate(\Vortos\Auth\Contract\UserIdentityInterface $i, string $f): FeatureAccessDecision
            { return FeatureAccessDecision::PaymentRequired; }
        };
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [$payment, new AlwaysDenyPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_allowed_only_when_all_policies_grant(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.basic']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro']),
            $routeMap,
            [new AlwaysAllowPolicy(), new AlwaysAllowPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_plan_based_policy_allows_pro(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro']),
            $routeMap,
            [new PlanBasedPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_plan_based_policy_denies_free(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'free']),
            $routeMap,
            [new PlanBasedPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_response_contains_feature_name(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [new AlwaysDenyPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $body = json_decode($response->getContent(), true);
        $this->assertSame('api.bulk_export', $body['feature']);
    }
}
