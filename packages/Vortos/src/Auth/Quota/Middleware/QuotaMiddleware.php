<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Quota\Contract\QuotaPolicyInterface;
use Vortos\Auth\Quota\Contract\QuotaSubjectResolverInterface;
use Vortos\Auth\Quota\Exception\QuotaStoreUnavailableException;
use Vortos\Auth\Quota\Exception\QuotaSubjectNotResolvedException;
use Vortos\Auth\Quota\QuotaConsumeResult;
use Vortos\Auth\Quota\QuotaFailureMode;
use Vortos\Auth\Quota\QuotaRule;
use Vortos\Auth\Quota\Storage\RedisQuotaStore;
use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Enforces #[RequiresQuota] on controllers.
 * Priority 0 — after feature access (1).
 * Zero reflection at runtime — reads compile-time map.
 *
 * For each rule, the most restrictive non-unlimited policy result wins.
 * Exactly one increment is performed per passing rule.
 */
final class QuotaMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{quota: string, cost: int, by: string}>> $routeMap
     * @param array<string, QuotaPolicyInterface> $policies
     * @param array<string, QuotaSubjectResolverInterface> $resolvers
     */
    public function __construct(
        private CurrentUserProvider $currentUser,
        private RedisQuotaStore $store,
        private array $routeMap,
        private array $policies,
        private array $resolvers,
        private QuotaFailureMode $failureMode = QuotaFailureMode::FailClosed,
        private bool $headersEnabled = true,
        private bool $problemDetailsEnabled = true,
        private ?MetricsInterface $metrics = null,
        private ?LoggerInterface $logger = null,
        private ?TracingInterface $tracer = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 0],
            KernelEvents::RESPONSE => ['onKernelResponse', 0],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->headersEnabled) return;

        $headers = $event->getRequest()->attributes->get('_quota_headers');
        if ($headers === null) return;

        $response = $event->getResponse();
        $response->headers->set('X-Quota-Name', (string) $headers['name']);
        $response->headers->set('X-Quota-Limit', (string) $headers['limit']);
        $response->headers->set('X-Quota-Remaining', (string) max(0, (int) $headers['remaining']));
        $response->headers->set('X-Quota-Reset', (string) $headers['reset']);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $controller = $this->extractControllerClass(
            $event->getRequest()->attributes->get('_controller')
        );

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) return;

        foreach ($this->routeMap[$controller] as $rule) {
            // Find the most restrictive non-unlimited quota across all policies
            $resolver = $this->resolvers[$rule['by']] ?? null;
            if (!$resolver instanceof QuotaSubjectResolverInterface) {
                $event->setResponse($this->problemResponse(
                    $event,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/quota-resolver-missing',
                    'Quota Resolver Missing',
                    'The quota subject resolver is not available.',
                    ['quota_name' => $rule['quota'], 'resolver' => $rule['by']],
                ));
                return;
            }

            $bucket = $resolver->bucket();
            if (!preg_match('/^[a-z0-9._-]+$/', $bucket)) {
                $event->setResponse($this->problemResponse(
                    $event,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/invalid-quota-bucket',
                    'Invalid Quota Bucket',
                    'The quota subject resolver returned an invalid bucket name.',
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                ));
                return;
            }

            $mostRestrictive = $this->resolveQuota($identity, $rule['quota'], $bucket);

            if ($mostRestrictive === null) continue;

            $subjectId = $resolver->resolve($identity);
            if ($subjectId === null || trim($subjectId) === '') {
                $this->logger?->warning('quota.subject_not_resolved', [
                    'quota' => $rule['quota'],
                    'resolver' => $rule['by'],
                    'bucket' => $bucket,
                ]);
                $this->trace('vortos.quota.allowed', false);

                $event->setResponse($this->problemResponse(
                    $event,
                    Response::HTTP_FORBIDDEN,
                    'https://docs.vortos.dev/errors/quota-subject-not-resolved',
                    'Quota Subject Not Resolved',
                    (new QuotaSubjectNotResolvedException($rule['by']))->getMessage(),
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                ));
                return;
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

                $event->setResponse($this->problemResponse(
                    $event,
                    Response::HTTP_SERVICE_UNAVAILABLE,
                    'https://docs.vortos.dev/errors/quota-store-unavailable',
                    'Quota Store Unavailable',
                    'Quota enforcement is temporarily unavailable.',
                    ['quota_name' => $rule['quota'], 'bucket' => $bucket],
                ));
                return;
            }

            $this->setQuotaHeaders($event, $rule['quota'], $mostRestrictive, $result);

            if (!$result->allowed) {
                $this->metrics?->counter('quota_blocked_total', [
                    'quota' => $rule['quota'],
                    'bucket' => $bucket,
                    'period' => $mostRestrictive->period->value,
                    'controller' => str_replace('\\', '.', $controller),
                ])->increment();
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

                $event->setResponse($this->problemResponse(
                    $event,
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
                ));
                return;
            }

            $this->metrics?->counter('quota_allowed_total', [
                'quota' => $rule['quota'],
                'bucket' => $bucket,
                'period' => $mostRestrictive->period->value,
                'controller' => str_replace('\\', '.', $controller),
            ])->increment();
            $this->metrics?->counter('quota_consumed_total', [
                'quota' => $rule['quota'],
                'bucket' => $bucket,
                'period' => $mostRestrictive->period->value,
                'controller' => str_replace('\\', '.', $controller),
            ])->increment($rule['cost']);
        }
    }

    /**
     * Returns the most restrictive (lowest limit) non-unlimited QuotaRule across all policies,
     * or null if every policy returns unlimited for this quota.
     */
    private function resolveQuota(mixed $identity, string $quota, string $bucket): ?QuotaRule
    {
        $result = null;

        foreach ($this->policies as $policy) {
            $rule = $policy->getQuota($identity, $quota, $bucket);
            if ($rule->isUnlimited()) continue;
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

    private function setQuotaHeaders(RequestEvent $event, string $quota, QuotaRule $rule, QuotaConsumeResult $result): void
    {
        $event->getRequest()->attributes->set('_quota_headers', [
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
        RequestEvent $event,
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
                'instance' => $event->getRequest()->getPathInfo(),
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

        $span = $this->tracer->startSpan('vortos.quota');
        $span->addAttribute($key, $value);
        $span->end();
    }
}
