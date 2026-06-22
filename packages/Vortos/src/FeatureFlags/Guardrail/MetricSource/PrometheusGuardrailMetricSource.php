<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\MetricSource;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;

final class PrometheusGuardrailMetricSource implements GuardrailMetricSourceInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $baseUrl,
    ) {}

    public function query(GuardrailMetricQuery $query): ?float
    {
        try {
            $promql = $this->buildPromQL($query);
            $now    = (new \DateTimeImmutable())->getTimestamp();
            $url    = rtrim($this->baseUrl, '/') . '/api/v1/query'
                . '?query=' . urlencode($promql)
                . '&time=' . $now;

            $request  = $this->requestFactory->createRequest('GET', $url);
            $response = $this->httpClient->sendRequest($request);

            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512);

            if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
                return null;
            }

            $result = $data['data']['result'] ?? [];
            if (!is_array($result) || count($result) === 0) {
                return null;
            }

            $value = $result[0]['value'][1] ?? null;

            return $value !== null ? (float) $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildPromQL(GuardrailMetricQuery $query): string
    {
        $name   = $query->flagName;
        $env    = $query->environment;
        $window = $query->windowSeconds . 's';

        return match ($query->metricKind) {
            GuardrailMetricKind::ErrorRate => sprintf(
                'rate(vortos_errors_total{flag="%s",env="%s"}[%s])',
                $name,
                $env,
                $window,
            ),
            GuardrailMetricKind::LatencyP99 => sprintf(
                'histogram_quantile(0.99, rate(vortos_flags_evaluation_duration_ms_bucket{flag="%s"}[%s]))',
                $name,
                $window,
            ),
            GuardrailMetricKind::LatencyP50 => sprintf(
                'histogram_quantile(0.50, rate(vortos_flags_evaluation_duration_ms_bucket{flag="%s"}[%s]))',
                $name,
                $window,
            ),
            GuardrailMetricKind::ExposureRateDrop => sprintf(
                'rate(vortos_flags_exposures_total{flag="%s",env="%s"}[%s])',
                $name,
                $env,
                $window,
            ),
            GuardrailMetricKind::Custom => sprintf(
                'rate(%s{flag="%s",env="%s"}[%s])',
                $query->customMetricName ?? 'unknown_metric',
                $name,
                $env,
                $window,
            ),
        };
    }
}
