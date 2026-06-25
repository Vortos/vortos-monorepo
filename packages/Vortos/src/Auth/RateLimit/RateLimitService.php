<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit;

use Psr\Log\LoggerInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\RateLimit\Contract\RateLimitPolicyInterface;
use Vortos\Auth\RateLimit\Contract\RateLimitStoreInterface;
use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\TelemetryRequestAttributes;
use Vortos\Tracing\Contract\TracingInterface;

final class RateLimitService
{
    /**
     * @param array<string, list<array{policy: string, per: RateLimitScope}>> $routeMap
     * @param array<string, RateLimitPolicyInterface> $policies
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private RateLimitStoreInterface $store,
        private array $routeMap,
        private array $policies,
        private RateLimitFailureConfig $failureConfig = new RateLimitFailureConfig(),
        private bool $problemDetailsEnabled = true,
        private IpResolverInterface $ipResolver = new \Vortos\Http\IpResolver\RemoteAddrIpResolver(),
        private ?FrameworkTelemetry $telemetry = null,
        private ?LoggerInterface $logger = null,
        private ?TracingInterface $tracer = null,
    ) {}

    public function enforce(Request $request, RateLimitScope ...$scopes): ?Response
    {
        $controller = $this->extractControllerClass($request->attributes->get('_controller'));

        if ($controller === null || !isset($this->routeMap[$controller])) {
            return null;
        }

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            $policyClass = $rule['policy'];
            $scope = $rule['per'];

            if (!in_array($scope, $scopes, true)) {
                continue;
            }
            if (!isset($this->policies[$policyClass])) {
                continue;
            }

            $policy = $this->policies[$policyClass];
            $limit = $policy->getLimit($identity);

            if ($limit->isUnlimited()) {
                continue;
            }

            $key = match ($scope) {
                RateLimitScope::User   => "rl:user:{$identity->id()}:{$controller}:{$policyClass}",
                RateLimitScope::Ip     => "rl:ip:{$this->resolveClientIp($request)}:{$controller}:{$policyClass}",
                RateLimitScope::Global => "rl:global:{$controller}:{$policyClass}",
            };

            try {
                $current = $this->store->increment($key, $limit->windowSeconds);
            } catch (RateLimitStoreUnavailableException $e) {
                return $this->handleStoreFailure($request, $scope, $policyClass, $controller, $e);
            }

            if ($current > $limit->limit) {
                try {
                    $retryAfter = $this->store->getTtl($key);
                } catch (RateLimitStoreUnavailableException) {
                    $retryAfter = $limit->windowSeconds;
                }

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

                return $this->problemResponse(
                    $request,
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
                );
            }

            $remaining = $limit->limit - $current;
            $existing = $request->attributes->get('_rate_limit_headers');
            if ($existing === null || $remaining < $existing['remaining']) {
                try {
                    $ttl = $this->store->getTtl($key);
                } catch (RateLimitStoreUnavailableException) {
                    $ttl = $limit->windowSeconds;
                }

                $request->attributes->set('_rate_limit_headers', [
                    'limit'     => $limit->limit,
                    'remaining' => $remaining,
                    'reset'     => time() + $ttl,
                ]);
            }

            $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::RateLimitAllowedTotal, $this->rateLimitLabels($policyClass, $scope, $controller));
        }

        return null;
    }

    public function applyHeaders(Request $request, Response $response): void
    {
        $rl = $request->attributes->get('_rate_limit_headers');
        if ($rl === null) {
            return;
        }

        $response->headers->set('X-RateLimit-Limit', (string) $rl['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $rl['remaining']));
        $response->headers->set('X-RateLimit-Reset', (string) $rl['reset']);
    }

    private function handleStoreFailure(
        Request $request,
        RateLimitScope $scope,
        string $policyClass,
        string $controller,
        RateLimitStoreUnavailableException $e,
    ): ?Response {
        $failureMode = $this->failureConfig->modeForScope($scope);

        $this->logger?->error('rate_limit.store_unavailable', [
            'scope' => $scope->value,
            'policy' => $policyClass,
            'controller' => $controller,
            'failure_mode' => $failureMode->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);

        $labels = $this->rateLimitLabels($policyClass, $scope, $controller);
        $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::RateLimitStoreUnavailableTotal, $labels);
        $this->trace('vortos.rate_limit.store_available', false);

        if ($failureMode === RateLimitFailureMode::FailOpen) {
            return null;
        }

        return $this->problemResponse(
            $request,
            Response::HTTP_SERVICE_UNAVAILABLE,
            'https://docs.vortos.dev/errors/rate-limit-store-unavailable',
            'Rate Limit Unavailable',
            'Rate limit enforcement is temporarily unavailable. Please retry later.',
            ['scope' => $scope->value, 'policy' => $policyClass],
            ['Retry-After' => 30],
        );
    }

    private function rateLimitLabels(string $policyClass, RateLimitScope $scope, string $controller): FrameworkMetricLabels
    {
        return FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Policy, str_replace('\\', '.', $policyClass)),
            MetricLabelValue::of(MetricLabel::Scope, $scope->value),
            MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
        );
    }

    private function resolveClientIp(Request $request): string
    {
        return $this->ipResolver->resolve($request);
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
        Request $request,
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
                'instance' => $request->getPathInfo(),
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
