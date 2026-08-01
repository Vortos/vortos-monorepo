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
use Vortos\Metrics\AutoInstrumentation\SecurityMetricDefinitions;
use Vortos\Metrics\Contract\CounterInterface;
use Vortos\Metrics\Contract\GaugeInterface;
use Vortos\Metrics\Contract\HistogramInterface;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Metrics\Definition\MetricDefinitionRegistry;
use Vortos\Metrics\Definition\MetricType;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;

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

/**
 * Validates emissions the way the real OTLP adapter does — same registry, same
 * exact-match label check — without standing up OpenTelemetry. A middleware that
 * emits a label set the definition does not declare throws here, exactly as it
 * did in production.
 */
final class ValidatingMetrics implements MetricsInterface
{
    /** @var list<array{name: string, labels: array<string, string>}> */
    public array $counters = [];

    private MetricDefinitionRegistry $registry;

    public function __construct()
    {
        $this->registry = new MetricDefinitionRegistry((new SecurityMetricDefinitions())->definitions());
    }

    public function counter(string $name, array $labels = []): CounterInterface
    {
        $definition = $this->registry->requireType($name, MetricType::Counter);
        $this->counters[] = ['name' => $name, 'labels' => $this->registry->validateLabels($definition, $labels)];

        return new class implements CounterInterface {
            public function increment(float $by = 1.0): void {}
        };
    }

    public function gauge(string $name, array $labels = []): GaugeInterface
    {
        throw new \LogicException('not used');
    }

    public function histogram(string $name, array $labels = []): HistogramInterface
    {
        throw new \LogicException('not used');
    }
}

final class FeatureAccessMiddlewareTest extends TestCase
{
    private function makeTelemetry(): FrameworkTelemetry
    {
        return new FrameworkTelemetry($this->metrics = new ValidatingMetrics());
    }

    private ValidatingMetrics $metrics;

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

    // ── Telemetry ─────────────────────────────────────────────────────────────
    // These wire real telemetry. Every test above passes $telemetry = null, which
    // is why an emission that could not satisfy its own definition shipped: the
    // allow path was one label short and threw inside the request it had just
    // permitted, turning every feature-gated route into a 500.

    public function test_allow_path_emits_a_metric_its_definition_accepts(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.basic']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro']),
            $routeMap,
            [new AlwaysAllowPolicy()],
            true,
            $this->makeTelemetry(),
        );

        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, $this->metrics->counters);
        $this->assertSame('feature_access_allowed_total', $this->metrics->counters[0]['name']);
        $this->assertSame(
            [
                'feature' => 'api.basic',
                'policy' => 'Vortos.Auth.Tests.FeatureAccess.AlwaysAllowPolicy',
                'controller' => 'App.TestCtrl',
            ],
            $this->metrics->counters[0]['labels'],
        );
    }

    public function test_deny_path_emits_a_metric_its_definition_accepts(): void
    {
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [new AlwaysDenyPolicy()],
            true,
            $this->makeTelemetry(),
        );

        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertCount(1, $this->metrics->counters);
        $this->assertSame('feature_access_denied_total', $this->metrics->counters[0]['name']);
        $this->assertSame(
            [
                'feature' => 'api.bulk_export',
                'policy' => 'Vortos.Auth.Tests.FeatureAccess.AlwaysDenyPolicy',
                'controller' => 'App.TestCtrl',
                'reason' => 'Forbidden',
            ],
            $this->metrics->counters[0]['labels'],
        );
    }

    public function test_payment_required_denial_records_its_own_reason(): void
    {
        // 402 and 403 must be separable in the metric, or "the plan never had it"
        // and "the card lapsed" look identical on a dashboard.
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.bulk_export']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(true, ['plan' => 'pro', 'subscription_active' => false]),
            $routeMap,
            [new SubscriptionPolicy()],
            true,
            $this->makeTelemetry(),
        );

        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('PaymentRequired', $this->metrics->counters[0]['labels']['reason']);
    }

    public function test_allow_with_no_policies_registered_still_emits(): void
    {
        // Nothing decided the allow, so there is no policy class to name. The
        // label is still required, so it has to carry a placeholder rather than
        // dereference null.
        $routeMap = ['App\TestCtrl' => [['feature' => 'api.basic']]];
        $middleware = new FeatureAccessMiddleware(
            $this->makeProvider(),
            $routeMap,
            [],
            true,
            $this->makeTelemetry(),
        );

        $response = $middleware->handle($this->makeRequest('App\TestCtrl'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('none', $this->metrics->counters[0]['labels']['policy']);
    }
}
