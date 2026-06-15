<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessDecision;
use Vortos\Auth\FeatureAccess\Contract\FeatureAccessPolicyInterface;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Enforces #[RequiresFeatureAccess] on controllers.
 * Runs at FEATURE_ACCESS (order 500) — after ownership, before quota.
 *
 * Maps the policy's FeatureAccessDecision to a status:
 * Forbidden → 403, PaymentRequired → 402. The policy, not the route, decides.
 *
 * Runtime: zero reflection — reads compile-time map.
 */
#[AsMiddleware(order: MiddlewareOrder::FEATURE_ACCESS)]
final class FeatureAccessMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, list<array{feature: string}>> $routeMap
     * @param array<string, FeatureAccessPolicyInterface> $policies
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private array $routeMap,
        private array $policies,
        private bool $problemDetailsEnabled = true,
        private ?FrameworkTelemetry $telemetry = null,
        private ?LoggerInterface $logger = null,
        private ?TracingInterface $tracer = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) {
            return $next($request);
        }

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            [$decision, $deciding] = $this->resolve($identity, $rule['feature']);

            if ($decision->isAllowed()) {
                $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::FeatureAccessAllowedTotal, FrameworkMetricLabels::of(
                    MetricLabelValue::of(MetricLabel::Feature, $rule['feature']),
                    MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
                ));
                continue;
            }

            $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::FeatureAccessDeniedTotal, FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Feature, $rule['feature']),
                MetricLabelValue::of(MetricLabel::Policy, str_replace('\\', '.', $deciding::class)),
                MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
                MetricLabelValue::of(MetricLabel::Reason, $decision->name),
            ));
            $this->logger?->warning('feature_access.denied', [
                'feature' => $rule['feature'],
                'policy' => $deciding::class,
                'decision' => $decision->name,
            ]);
            $this->trace('vortos.feature_access.allowed', false);

            $paymentRequired = $decision === FeatureAccessDecision::PaymentRequired;

            return $this->problemResponse(
                $request,
                $decision->httpStatus(),
                $paymentRequired
                    ? 'https://docs.vortos.dev/errors/payment-required'
                    : 'https://docs.vortos.dev/errors/feature-access-denied',
                $paymentRequired ? 'Payment Required' : 'Forbidden',
                $paymentRequired
                    ? 'This feature requires an active subscription.'
                    : 'Your plan does not include access to this feature.',
                [
                    'feature' => $rule['feature'],
                    'payment_required' => $paymentRequired,
                ],
            );
        }

        return $next($request);
    }

    /**
     * Evaluates every policy for a feature and returns the most restrictive
     * decision (Forbidden > PaymentRequired > Allowed) together with the policy
     * that produced it. A feature is allowed only if no policy denies it.
     *
     * @return array{0: FeatureAccessDecision, 1: FeatureAccessPolicyInterface}
     */
    private function resolve(\Vortos\Auth\Contract\UserIdentityInterface $identity, string $feature): array
    {
        $decision = FeatureAccessDecision::Allowed;
        $deciding = null;

        foreach ($this->policies as $policy) {
            $result = $policy->evaluate($identity, $feature);
            if ($deciding === null || $result->weight() > $decision->weight()) {
                $decision = $result;
                $deciding = $policy;
            }
        }

        return [$decision, $deciding];
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) return explode('::', $controller)[0];
        if (is_array($controller)) return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        if (is_object($controller)) return get_class($controller);
        return null;
    }

    /**
     * @param array<string, mixed> $extensions
     */
    private function problemResponse(
        Request $request,
        int $status,
        string $type,
        string $title,
        string $detail,
        array $extensions = [],
    ): JsonResponse {
        if (!$this->problemDetailsEnabled) {
            return new JsonResponse(['error' => $title, 'message' => $detail] + $extensions, $status);
        }

        return new JsonResponse(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $request->getPathInfo(),
                'extensions' => $extensions,
            ] + $extensions,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    private function trace(string $key, mixed $value): void
    {
        if ($this->tracer === null) {
            return;
        }

        $span = $this->tracer->startSpan('vortos.feature_access', [
            'vortos.module' => ObservabilityModule::Auth,
        ]);
        $span->addAttribute($key, $value);
        $span->end();
    }
}
