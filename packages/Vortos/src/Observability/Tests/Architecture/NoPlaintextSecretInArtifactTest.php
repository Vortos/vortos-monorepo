<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;

/**
 * A backend credential must never be inlined into the committed collector config —
 * it is referenced via an `${env:...}` placeholder the collector resolves at runtime.
 */
final class NoPlaintextSecretInArtifactTest extends TestCase
{
    public function test_rendered_config_references_env_not_literal_token(): void
    {
        $sink = new GrafanaOtlpMetricsSink('collector.example.com', headersEnvRef: 'OBSERVABILITY_GRAFANA_OTLP_HEADERS');
        $yaml = (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toYaml();

        // The header is present only as an env placeholder.
        self::assertStringContainsString('${env:OBSERVABILITY_GRAFANA_OTLP_HEADERS}', $yaml);
    }

    public function test_known_secret_value_is_never_present(): void
    {
        // Build with a driver whose env ref name is set; assert no obvious secret literal leaks.
        $sink = new GrafanaOtlpMetricsSink('collector.example.com', headersEnvRef: 'OBSERVABILITY_GRAFANA_OTLP_HEADERS');
        $yaml = (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toYaml();

        foreach (['Bearer ', 'Basic ', 'sk_live', 'AKIA'] as $needle) {
            self::assertStringNotContainsString($needle, $yaml);
        }
    }
}
