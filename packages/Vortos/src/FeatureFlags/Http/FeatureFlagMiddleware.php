<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Vortos\FeatureFlags\Exception\FeatureNotAvailableException;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\MetricResult;
use Vortos\Observability\Telemetry\TelemetryLabels;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Enforces #[RequiresFlag] on controllers.
 *
 * The flag map is built at container compile time by FeatureFlagsCompilerPass —
 * no reflection occurs on the request path. Lookup is a plain array read.
 *
 * Runs at priority 5, after RouterListener (8) has set _controller.
 */
final class FeatureFlagMiddleware implements EventSubscriberInterface
{
    public function __construct(
        private readonly FlagRegistryInterface $registry,
        private readonly FlagContextResolverInterface $contextResolver,
        private readonly array $flagMap = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly ?FrameworkTelemetry $telemetry = null,
        private readonly ?TracingInterface $tracer = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 5]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request    = $event->getRequest();
        $controller = $request->attributes->get('_controller');

        if ($controller === null) {
            return;
        }

        $class = $this->extractClass($controller);

        if ($class === null) {
            return;
        }

        $method   = is_array($controller) ? ($controller[1] ?? '__invoke') : '__invoke';
        $flagName = $this->resolveFlag($class, $method);

        if ($flagName === null) {
            return;
        }

        $context = $this->contextResolver->resolve($request);
        $controllerLabel = TelemetryLabels::dottedClass($class);
        $flagLabel = TelemetryLabels::safe($flagName);
        $span = $this->tracer?->startSpan('feature_flag.evaluate', [
            'vortos.module' => ObservabilityModule::FeatureFlags,
            'vortos.feature_flag.name' => $flagLabel,
            'code.namespace' => $class,
            'code.function' => $method,
        ]);

        try {
            $enabled = $this->registry->isEnabled($flagName, $context);
            $result = $enabled ? 'enabled' : 'disabled';
            $this->telemetry?->increment(
                ObservabilityModule::FeatureFlags,
                FrameworkMetric::FeatureFlagEvaluationsTotal,
                FrameworkMetricLabels::of(
                    MetricLabelValue::of(MetricLabel::Flag, $flagLabel),
                    MetricLabelValue::result($enabled ? MetricResult::Enabled : MetricResult::Disabled),
                    MetricLabelValue::of(MetricLabel::Controller, $controllerLabel),
                ),
            );
            $span?->addAttribute('vortos.feature_flag.result', $result);
            $span?->setStatus($enabled ? 'ok' : 'error');

            if (!$enabled) {
                $this->logger?->warning('feature_flag.denied', [
                    'flag' => $flagLabel,
                    'controller' => $controllerLabel,
                ]);
                throw new FeatureNotAvailableException($flagName);
            }
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    private function resolveFlag(string $class, string $method): ?string
    {
        return $this->flagMap[$class . '::' . $method]
            ?? $this->flagMap[$class . '::__invoke']
            ?? null;
    }

    private function extractClass(mixed $controller): ?string
    {
        try {
            if (is_string($controller)) {
                return explode('::', $controller)[0];
            }

            if (is_array($controller)) {
                return is_object($controller[0]) ? get_class($controller[0]) : $controller[0];
            }

            if (is_object($controller)) {
                return get_class($controller);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('FeatureFlagMiddleware: failed to extract controller class', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
