<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
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
 * Priority 1 — after ownership (2) and authorization (3).
 *
 * Returns 403 when denied.
 * Returns 402 (Payment Required) when paymentRequired: true.
 *
 * Runtime: zero reflection — reads compile-time map.
 */
final class FeatureAccessMiddleware implements EventSubscriberInterface
{
    /**
     * @param array<string, list<array{feature: string, paymentRequired: bool}>> $routeMap
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

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 1]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) return;

        $controller = $this->extractControllerClass(
            $event->getRequest()->attributes->get('_controller')
        );

        if ($controller === null || !isset($this->routeMap[$controller])) return;

        $identity = $this->currentUser->get();

        foreach ($this->routeMap[$controller] as $rule) {
            foreach ($this->policies as $policy) {
                if (!$policy->canAccess($identity, $rule['feature'])) {
                    $status = $rule['paymentRequired']
                        ? Response::HTTP_PAYMENT_REQUIRED
                        : Response::HTTP_FORBIDDEN;

                    $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::FeatureAccessDeniedTotal, FrameworkMetricLabels::of(
                        MetricLabelValue::of(MetricLabel::Feature, $rule['feature']),
                        MetricLabelValue::of(MetricLabel::Policy, str_replace('\\', '.', $policy::class)),
                        MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
                    ));
                    $this->logger?->warning('feature_access.denied', [
                        'feature' => $rule['feature'],
                        'policy' => $policy::class,
                        'payment_required' => $rule['paymentRequired'],
                    ]);
                    $this->trace('vortos.feature_access.allowed', false);

                    $event->setResponse($this->problemResponse(
                        $event,
                        $status,
                        $rule['paymentRequired']
                            ? 'https://docs.vortos.dev/errors/payment-required'
                            : 'https://docs.vortos.dev/errors/feature-access-denied',
                        $rule['paymentRequired'] ? 'Payment Required' : 'Forbidden',
                        $rule['paymentRequired']
                            ? 'This feature requires an active subscription.'
                            : 'Your plan does not include access to this feature.',
                        [
                            'feature' => $rule['feature'],
                            'payment_required' => $rule['paymentRequired'],
                        ],
                    ));
                    return;
                }

                $this->telemetry?->increment(ObservabilityModule::Auth, FrameworkMetric::FeatureAccessAllowedTotal, FrameworkMetricLabels::of(
                    MetricLabelValue::of(MetricLabel::Feature, $rule['feature']),
                    MetricLabelValue::of(MetricLabel::Policy, str_replace('\\', '.', $policy::class)),
                    MetricLabelValue::of(MetricLabel::Controller, str_replace('\\', '.', $controller)),
                ));
            }
        }
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

        $span = $this->tracer->startSpan('vortos.feature_access', [
            'vortos.module' => ObservabilityModule::Auth,
        ]);
        $span->addAttribute($key, $value);
        $span->end();
    }
}
