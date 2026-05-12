<?php

declare(strict_types=1);

namespace Vortos\Metrics\Http;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Vortos\Http\Attribute\ApiController;
use Vortos\Metrics\Contract\MetricsCollectorInterface;

/**
 * Exposes Prometheus metrics at GET /metrics.
 *
 * Only registered when MetricsAdapter::Prometheus is active (MetricsExtension handles this).
 * Protected by Bearer token when prometheusEndpointToken() is configured.
 *
 * ## Security
 *
 * The /metrics endpoint MUST NOT be exposed to the public internet.
 * Restrict access via:
 *   - Bearer token: VortosMetricsConfig::prometheusEndpointToken('secret')
 *   - Network-level: firewall rules or reverse proxy IP allowlist
 *   - Both for defence-in-depth
 *
 * ## Prometheus scrape config
 *
 *   scrape_configs:
 *     - job_name: 'vortos'
 *       static_configs:
 *         - targets: ['app:80']
 *       metrics_path: '/metrics'
 *       bearer_token: 'your-secret-here'
 */
#[ApiController]
#[Route('/metrics', name: 'vortos.metrics', methods: ['GET'])]
final class MetricsController
{
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly string $token = '',
        private readonly iterable $collectors = [],
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($this->token !== '' && !$this->isAuthorized($request)) {
            return new Response('Unauthorized', Response::HTTP_UNAUTHORIZED, [
                'WWW-Authenticate' => 'Bearer realm="metrics"',
            ]);
        }

        foreach ($this->collectors as $collector) {
            if ($collector instanceof MetricsCollectorInterface) {
                $collector->collect();
            }
        }

        $renderer = new RenderTextFormat();
        $result   = $renderer->render($this->registry->getMetricFamilySamples());

        return new Response($result, Response::HTTP_OK, [
            'Content-Type' => RenderTextFormat::MIME_TYPE,
        ]);
    }

    private function isAuthorized(Request $request): bool
    {
        $header = $request->headers->get('Authorization', '');
        return hash_equals('Bearer ' . $this->token, $header);
    }
}
