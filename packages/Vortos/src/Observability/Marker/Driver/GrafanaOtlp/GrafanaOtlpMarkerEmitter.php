<?php

declare(strict_types=1);

namespace Vortos\Observability\Marker\Driver\GrafanaOtlp;

use Throwable;
use Vortos\Observability\Marker\AnnotationRenderer;
use Vortos\Observability\Marker\Capability\MarkerCapability;
use Vortos\Observability\Marker\DeployMarker;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\Observability\Marker\MarkerTransportInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Renders a deploy marker as an OTLP log record and posts it to the same off-host
 * Grafana OTLP gateway as {@see \Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink}
 * (Block 16, §3.2). Auth is read via `${env:...}` at use-time, never logged.
 * `emit()` never throws — failures are swallowed (best-effort contract).
 */
#[AsDriver('grafana')]
final class GrafanaOtlpMarkerEmitter implements MarkerEmitterInterface
{
    public function __construct(
        private readonly string $endpointUrl,
        private readonly MarkerTransportInterface $transport,
        private readonly ?string $authHeaderEnvRef = 'OBSERVABILITY_GRAFANA_OTLP_HEADERS',
        private readonly AnnotationRenderer $renderer = new AnnotationRenderer(),
    ) {
    }

    public function name(): string
    {
        return 'grafana';
    }

    public function emit(DeployMarker $marker): void
    {
        $headers = [];
        if ($this->authHeaderEnvRef !== null) {
            $value = getenv($this->authHeaderEnvRef);
            if ($value !== false && $value !== '') {
                $headers['Authorization'] = $value;
            }
        }

        try {
            $this->transport->post($this->endpointUrl, $this->renderer->render($marker), $headers);
        } catch (Throwable) {
            // Best-effort: a backend outage must never fail or block a deploy.
        }
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            MarkerCapability::Annotations->value => true,
            MarkerCapability::OffHost->value => true,
            MarkerCapability::Tls->value => str_starts_with($this->endpointUrl, 'https://'),
        ]);
    }
}
