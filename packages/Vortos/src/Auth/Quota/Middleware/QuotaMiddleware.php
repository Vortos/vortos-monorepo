<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\JsonResponse;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\Exception\QuotaStoreUnavailableException;
use Vortos\Auth\Quota\Exception\QuotaSubjectNotResolvedException;
use Vortos\Auth\Quota\QuotaConsumeResult;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Quota\QuotaPeriod;
use Vortos\Auth\Quota\QuotaRule;
use Vortos\Auth\Quota\QuotaSubjectProvenance;
use Vortos\Auth\Quota\Contract\QuotaStoreInterface;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Enforces #[RequiresQuota] on controllers.
 * Runs at QUOTA (order 200) — innermost business rule, after feature access.
 * Zero reflection at runtime — reads compile-time map.
 *
 * For each rule, the most restrictive non-unlimited policy result wins.
 * Exactly one increment is performed per passing rule.
 */
#[AsMiddleware(order: MiddlewareOrder::QUOTA)]
final class QuotaMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, list<array{quota: string, cost: int, by: string}>> $routeMap
     * @param array<string, QuotaPolicyInterface> $policies
     * @param array<string, QuotaSubjectResolverInterface> $resolvers
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private QuotaStoreInterface $store,
        private array $routeMap,
        private array $policies,
        private array $resolvers,
        private QuotaFailureMode $failureMode = QuotaFailureMode::FailClosed,
        private bool $headersEnabled = true,
        private bool $problemDetailsEnabled = true,
        private bool $compensateOnServerError = true,
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
        $isAuthenticated = $identity->isAuthenticated();

        /** @var list<array{bucket: string, subjectId: string, quota: string, period: QuotaPeriod, cost: int}> */
        $consumed = [];

        foreach ($this->routeMap[$controller] as $rule) {
            $resolver = $this->resolvers[$rule['by']] ?? null;
            if (!$resolver instanceof QuotaSubjectResolverInterface) {
                return $this->problemResponse(
                    $request,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/quota-resolver-missing',
                    'Quota Resolver Missing',
                    'The quota subject resolver is not available.',
                    ['quota_name' => $rule['quota'], 'resolver' => $rule['by']],
                );
            }

            if ($resolver->provenance() === QuotaSubjectProvenance::ClaimDerived) {
                $this->logger?->error('quota.unsafe_subject_provenance', [
                    'quota' => $rule['quota'],
                    'resolver' => $rule['by'],
                ]);

                return $this->problemResponse(
                    $request,
                    Response::HTTP_INTERNAL_SERVER_ERROR,
                    'https://docs.vortos.dev/errors/quota-unsafe-provenance',
                    'Quota Configuration Error',
                    'Quota subject resolver uses claim-derived provenance which is not permitted.',
                    ['quota_name' => $rule['quota'], 'resolver' => $rule['by']],
                );
            }

            if ($resolver->requiresAuthentication() && !$isAuthenticated) {
                continue;
            }

            $bucket = $resolver->bucket();
            if (!preg_match('/^[a-z0-9._-]+$/', $bucket)) {
                return $this->problemResponse(
                    $request,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/invalid-quota-bucket',
                    'Invalid Quota Bucket',
                    'The quota subject resolver returned an invalid bucket name.',
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                );
            }

            $mostRestrictive = $this->resolveQuota($identity, $rule['quota'], $bucket);

            if ($mostRestrictive === null) {
                continue;
            }

            $subjectId = $resolver->resolve($identity);
            if ($subjectId === null || trim($subjectId) === '') {
                $this->logger?->warning('quota.subject_not_resolved', [
                    'quota' => $rule['quota'],
                    'resolver' => $rule['by'],
                    'bucket' => $bucket,
                ]);
                $this->trace('vortos.quota.allowed', false);

                return $this->problemResponse(
                    $request,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/quota-subject-not-resolved',
                    'Quota Subject Not Resolved',
                    (new QuotaSubjectNotResolvedException($rule['by']))->getMessage(),
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                );
            }

            try {
                $result = $this->store->consume(
                    bucket: $bucket,
                    subjectId: $subjectId,
                    quota: $rule['quota'],
                    period: $mostRestrictive->period,
                    limit: $mostRestrictive->limit,
                    cost: $rule['cost'],
                );
            } catch (QuotaStoreUnavailableException $e) {
                $this->logger?->error('quota.store_unavailable', [
                    'quota' => $rule['quota'],
                    'bucket' => $bucket,
                    'failure_mode' => $this->failureMode->value,
                    'exception' => $e::class,
                ]);
                $this->trace('vortos.quota.store_available', false);

                if ($this->failureMode === QuotaFailureMode::FailOpen) {
                    continue;
                }

                return $this->problemResponse(
                    $request,
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'https://docs.vortos.dev/errors/quota-store-unavailable',
                    'Quota Store Unavailable',
                    'Quota enforcement is temporarily unavailable.',
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                );
            }

            $this->storeQuotaHeaders($request, $rule['quota'], $mostRestrictive, $result);

            if (!$result->allowed) {
                $labels = $this->quotaLabels($rule['quota'], $bucket, $mostRestrictive->period->value, $controller);
                $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::QuotaBlockedTotal, $labels);
                $this->logger?->warning('quota.exceeded', [
                    'quota' => $rule['quota'],
                    'bucket' => $bucket,
                    'limit' => $mostRestrictive->limit,
                    'current' => $result->current,
                    'remaining' => $result->remaining,
                    'period' => $mostRestrictive->period->value,
                    'reset_at' => $result->resetAt,
                ]);
                $this->trace('vortos.quota.allowed', false);

                return $this->problemResponse(
                    $request,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/quota-exceeded',
                    'Quota Exceeded',
                    sprintf(
                        'You have exceeded your %s limit of %d %s.',
                        $mostRestrictive->period->value,
                        $mostRestrictive->limit,
                        $rule['quota'],
                    ),
                    [
                        'quota_name' => $rule['quota'],
                        'bucket' => $bucket,
                        'limit' => $mostRestrictive->limit,
                        'remaining' => $result->remaining,
                        'reset_at' => $result->resetAt,
                    ],
                );
            }

            $consumed[] = [
                'bucket' => $bucket,
                'subjectId' => $subjectId,
                'quota' => $rule['quota'],
                'period' => $mostRestrictive->period,
                'cost' => $rule['cost'],
            ];

            $labels = $this->quotaLabels($rule['quota'], $bucket, $mostRestrictive->period->value, $controller);
            $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::QuotaAllowedTotal, $labels);
            $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::QuotaConsumedTotal, $labels, $rule['cost']);
        }

        $response = $next($request);

        if ($this->compensateOnServerError && $response->getStatusCode() >= 500 && $consumed !== []) {
            $this->compensateConsumed($consumed, $controller);
        }

        if ($this->headersEnabled) {
            $headers = $request->attributes->get('_quota_headers');
            if ($headers !== null) {
                $response->headers->set('X-Quota-Name', (string) $headers['name']);
                $response->headers->set('X-Quota-Limit', (string) $headers['limit']);
                $response->headers->set('X-Quota-Remaining', (string) max(0, (int) $headers['remaining']));
                $response->headers->set('X-Quota-Reset', (string) $headers['reset']);
            }
        }

        return $response;
    }

    /**
     * @param list<array{bucket: string, subjectId: string, quota: string, period: QuotaPeriod, cost: int}> $consumed
     */
    private function compensateConsumed(array $consumed, string $controller): void
    {
        foreach ($consumed as $entry) {
            try {
                $this->store->compensate(
                    bucket: $entry['bucket'],
                    subjectId: $entry['subjectId'],
                    quota: $entry['quota'],
                    period: $entry['period'],
                    cost: $entry['cost'],
                );

                $labels = $this->quotaLabels($entry['quota'], $entry['bucket'], $entry['period']->value, $controller);
                $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::QuotaConsumedTotal, $labels, -$entry['cost']);
            } catch (\Throwable $e) {
                $this->logger?->warning('quota.compensate_failed', [
                    'quota' => $entry['quota'],
                    'bucket' => $entry['bucket'],
                    'cost' => $entry['cost'],
                    'exception' => $e::class,
                ]);
            }
        }
    }

    private function quotaLabels(string $quota, string $bucket, string $period, string $controller): FrameworkMetricLabels
    {
        return FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Quota, $quota),
            MetricLabelValue::of(MetricLabel::Bucket, $bucket),
            MetricLabelValue::of(MetricLabel::Period, $period),
            MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
        );
    }

    private function resolveQuota(mixed $identity, string $quota, string $bucket): ?QuotaRule
    {
        $result = null;

        foreach ($this->policies as $policy) {
            $rule = $policy->getQuota($identity, $quota, $bucket);
            if ($rule->isUnlimited()) {
                continue;
            }
            if ($result === null || $rule->limit < $result->limit) {
                $result = $rule;
            }
        }

        return $result;
    }

    private function extractControllerClass(mixed $controller): ?string
    {
        if (is_string($controller)) return explode('::', $controller)[0];
        if (is_array($controller)) return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
        if (is_object($controller)) return get_class($controller);
        return null;
    }

    private function storeQuotaHeaders(Request $request, string $quota, QuotaRule $rule, QuotaConsumeResult $result): void
    {
        $request->attributes->set('_quota_headers', [
            'name' => $quota,
            'limit' => $rule->limit,
            'remaining' => $result->remaining,
            'reset' => $result->resetAt,
        ]);
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

        $span = $this->tracer->startSpan('vortos.quota', [
            'vortos.module' => ObservabilityModule::Auth,
        ]);
        $span->addAttribute($key, $value);
        $span->end();
    }
}
