<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\RateLimit\Attribute\RateLimit;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\RateLimitScope;
use Vortos\Auth\RateLimit\Storage\RedisRateLimitStore;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Enforces #[RateLimit] on controllers.
 *
 * Scope-aware execution order:
 *
 *   Priority 7 — IP + Global scopes (onKernelRequestIpGlobal)
 *     Runs before AuthMiddleware (6). Does not need identity — keyed on IP
 *     or controller name only. Protects unauthenticated endpoints (e.g. login).
 *
 *   Priority 5 — User scope (onKernelRequestUser)
 *     Runs after AuthMiddleware (6). Identity is resolved and real user ID is
 *     available. Each user gets their own isolated rate limit bucket.
 *
 * Returns 429 with Retry-After header when limit exceeded.
 * Sets X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset on all responses.
 *
 * Runtime: reads pre-built compile-time map — zero reflection.
 */
final class RateLimitMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{policy: string, per: RateLimitScope}>> $routeMap
     *        Pre-built by RateLimitCompilerPass at compile time.
     * @param array<string, RateLimitPolicyInterface> $policies
     *        Pre-built policy map by RateLimitCompilerPass.
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private RedisRateLimitStore $store,
        private array $routeMap,
        private array $policies,
        private bool $headersEnabled = true,
        private bool $problemDetailsEnabled = true,
        private ?FrameworkTelemetry $telemetry = null,
        private ?LoggerInterface $logger = null,
        private ?TracingInterface $tracer = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequestIpGlobal', 7], // IP + Global — before auth
                ['onKernelRequestUser', 4],      // User — after auth (4, below 2FA at 5)
            ],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    /**
     * Enforces IP-scoped and Global-scoped rate limits.
     * Runs at priority 7 — before auth — so unauthenticated requests are covered.
     */
    public function onKernelRequestIpGlobal(RequestEvent $event): void
    {
        $this->enforce($event, RateLimitScope::Ip, RateLimitScope::Global);
    }

    /**
     * Enforces User-scoped rate limits.
     * Runs at priority 5 — after AuthMiddleware (6) has resolved the identity.
     * If auth rejected the request, propagation is already stopped and this won't fire.
     */
    public function onKernelRequestUser(RequestEvent $event): void
    {
        $this->enforce($event, RateLimitScope::User);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $rl = $event->getRequest()->attributes->get('_rate_limit_headers');
        if ($rl === null) return;
        if (!$this->headersEnabled) return;

        $response = $event->getResponse();
        $response->headers->set('X-RateLimit-Limit', (string) $rl['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $rl['remaining']));
        $response->headers->set('X-RateLimit-Reset', (string) $rl['reset']);
    }

    private function enforce(RequestEvent $event, RateLimitScope ...$scopes): void
    {
        if (!$event->isMainRequest()) return;

        $request = $event->getRequest();
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            $policyClass = $rule['policy'];
            $scope = $rule['per'];

            if (!in_array($scope, $scopes, true)) continue;
            if (!isset($this->policies[$policyClass])) continue;

            $policy = $this->policies[$policyClass];
            $limit = $policy->getLimit($identity);

            if ($limit->isUnlimited()) continue;

            $key = match ($scope) {
                RateLimitScope::User   => "rl:user:{$identity->id()}:{$controller}:{$policyClass}",
                RateLimitScope::Ip     => "rl:ip:{$request->getClientIp()}:{$controller}:{$policyClass}",
                RateLimitScope::Global => "rl:global:{$controller}:{$policyClass}",
            };

            $current = $this->store->increment($key, $limit->windowSeconds);

            if ($current > $limit->limit) {
                $retryAfter = $this->store->getTtl($key);
                $resetAt = time() + $retryAfter;
                $request->attributes->set('_rate_limit_headers', [
                    'limit' => $limit->limit,
                    'remaining' => 0,
                    'reset' => $resetAt,
                ]);
                $request->attributes->set(TelemetryRequestAttributes::DROP_TRACE, true);
                $request->attributes->set(TelemetryRequestAttributes::BLOCKED_REASON, 'rate_limit');

                $labels = $this->rateLimitLabels($policyClass, $scope, $controller);
                $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::RateLimitBlockedTotal, $labels);
                $this->logger?->warning('rate_limit.exceeded', [
                    'policy' => $policyClass,
                    'scope' => $scope->value,
                    'limit' => $limit->limit,
                    'window_seconds' => $limit->windowSeconds,
                    'retry_after' => $retryAfter,
                ]);
                $this->trace('vortos.rate_limit.allowed', false);

                $event->setResponse($this->problemResponse(
                    $event,
                    Response::HTTP_TOO_MANY_REQUESTS,
                    'https://docs.vortos.dev/errors/rate-limit-exceeded',
                    'Rate Limit Exceeded',
                    sprintf('Too many requests. Please retry after %d seconds.', $retryAfter),
                    [
                        'policy' => $policyClass,
                        'scope' => $scope->value,
                        'limit' => $limit->limit,
                        'remaining' => 0,
                        'reset_at' => $resetAt,
                        'retry_after' => $retryAfter,
                    ],
                    ['Retry-After' => $retryAfter],
                ));
                return;
            }

            // Track the most restrictive limit for response headers
            $remaining = $limit->limit - $current;
            $existing = $request->attributes->get('_rate_limit_headers');
            if ($existing === null || $remaining < $existing['remaining']) {
                $request->attributes->set('_rate_limit_headers', [
                    'limit'     => $limit->limit,
                    'remaining' => $remaining,
                    'reset'     => time() + $this->store->getTtl($key),
                ]);
            }

            $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::RateLimitAllowedTotal, $this->rateLimitLabels($policyClass, $scope, $controller));
        }
    }

    private function rateLimitLabels(string $policyClass, RateLimitScope $scope, string $controller): FrameworkMetricLabels
    {
        return FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Policy, str_replace('\\', '.', $policyClass)),
            MetricLabelValue::of(MetricLabel::Scope, $scope->value),
            MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
        );
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
     * @param array<string, int|string> $headers
     */
    private function problemResponse(
        RequestEvent $event,
        int $status,
        string $type,
        string $title,
        string $detail,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        if (!$this->problemDetailsEnabled) {
            return new JsonResponse(['error' => $title, 'message' => $detail] + $extensions, $status, $headers);
        }

        return new JsonResponse(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => $event->getRequest()->getPathInfo(),
                'extensions' => $extensions,
            ] + $extensions,
            $status,
            ['Content-Type' => 'application/problem+json'] + $headers,
        );
    }

    private function trace(string $key, mixed $value): void
    {
        if ($this->tracer === null) {
            return;
        }

        $span = $this->tracer->startSpan('vortos.rate_limit', [
            'vortos.module' => ObservabilityModule::Auth,
        ]);
        $span->addAttribute($key, $value);
        $span->end();
    }
}
