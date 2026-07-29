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
        $config = (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toArray();

        // The redaction processors are excluded before scanning, and must be: they exist precisely
        // to name the shapes of the things they block, so they legitimately contain the literals
        // "AKIA" and "Bearer" as regexes. Scanning them would make this test fire on the presence
        // of the defence rather than the presence of a secret. Everything else — exporters,
        // headers, extensions, pipelines — is still scanned, which is where a credential could
        // actually be inlined.
        foreach (array_keys($config['processors'] ?? []) as $name) {
            if (str_starts_with((string) $name, 'transform/vortos_')) {
                unset($config['processors'][$name]);
            }
        }

        $scanned = json_encode($config, JSON_THROW_ON_ERROR);

        foreach (['Bearer ', 'Basic ', 'sk_live', 'AKIA'] as $needle) {
            self::assertStringNotContainsString($needle, $scanned);
        }
    }

    /**
     * The exclusion above must not become a hiding place: whatever is skipped has to be a redaction
     * processor and nothing else.
     */
    public function test_only_redaction_processors_are_exempt_from_the_secret_scan(): void
    {
        $sink = new GrafanaOtlpMetricsSink('collector.example.com', headersEnvRef: 'OBSERVABILITY_GRAFANA_OTLP_HEADERS');
        $config = (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toArray();

        foreach (array_keys($config['processors'] ?? []) as $name) {
            if (!str_starts_with((string) $name, 'transform/vortos_')) {
                continue;
            }

            $processor = $config['processors'][$name];
            self::assertArrayHasKey('error_mode', $processor);
            self::assertTrue(
                isset($processor['trace_statements']) || isset($processor['log_statements']),
                sprintf('"%s" is exempt from the secret scan but is not a redaction processor.', $name),
            );
        }
    }
}
