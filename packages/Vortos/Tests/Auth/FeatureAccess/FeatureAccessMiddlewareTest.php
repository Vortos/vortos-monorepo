<?php
declare(strict_types=1);

namespace Vortos\Tests\Auth\FeatureAccess;

use PHPUnit\Framework\TestCase;
use Vortos\Http\Request;
use Vortos\Http\Response;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessPolicyInterface;
use Vortos\Auth\FeatureAccess\Middleware\FeatureAccessMiddleware;
use Vortos\Auth\Identity\AnonymousIdentity;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Cache\Adapter\ArrayAdapter as CacheArrayAdapter;

final class AlwaysDenyPolicy implements FeatureAccessPolicyInterface
{
    public function canAccess(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): bool
    {
        return false;
    }
}

final class AlwaysAllowPolicy implements FeatureAccessPolicyInterface
{
    public function canAccess(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): bool
    {
        return true;
    }
}

final class PlanBasedPolicy implements FeatureAccessPolicyInterface
{
    public function canAccess(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): bool
    {
        $plan = $identity->getAttribute('plan', 'free');
        return match($feature) {
            'api.bulk_export' => $plan === 'pro',
            default           => true,
        };
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
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.basic', 'paymentRequired' => false]]];
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
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export', 'paymentRequired' => false]]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [new AlwaysDenyPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_returns_402_when_payment_required(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export', 'paymentRequired' => true]]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [new AlwaysDenyPolicy()]
        );
        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());
        $this->assertSame(402, $response->getStatusCode());
    }

    public function test_plan_based_policy_allows_pro(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export', 'paymentRequired' => false]]];
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
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export', 'paymentRequired' => false]]];
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
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export', 'paymentRequired' => false]]];
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
