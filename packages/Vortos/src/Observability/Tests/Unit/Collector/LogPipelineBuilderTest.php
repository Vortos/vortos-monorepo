<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorConfig;
use Vortos\Observability\Collector\LogPipelineBuilder;
use Vortos\Observability\Collector\LogPipelineConfig;
use Vortos\Observability\Collector\LogRedactionPolicy;

final class LogPipelineBuilderTest extends TestCase
{
    private function baseConfig(): CollectorConfig
    {
        return new CollectorConfig([
            'extensions' => [],
            'receivers' => [
                'otlp' => ['protocols' => ['grpc' => ['endpoint' => '127.0.0.1:4317']]],
            ],
            'processors' => ['memory_limiter' => [], 'batch' => []],
            'exporters' => [],
            'service' => ['extensions' => [], 'pipelines' => []],
        ]);
    }

    public function test_merges_filelog_receiver_and_redaction_processor(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/lib/docker/containers/*/*.log']);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => ['endpoint' => 'https://logs.example.invalid']])->toArray();

        self::assertArrayHasKey('filelog/vortos', $merged['receivers']);
        self::assertSame(['/var/lib/docker/containers/*/*.log'], $merged['receivers']['filelog/vortos']['include']);
        self::assertArrayHasKey('transform/vortos_logs', $merged['processors']);
        self::assertSame(['filelog/vortos'], $merged['service']['pipelines']['logs']['receivers']);
    }

    public function test_filelog_receiver_unwraps_docker_envelope_and_parses_json(): void
    {
        $builder = new LogPipelineBuilder();
        $merged = $builder->merge($this->baseConfig(), new LogPipelineConfig(), ['type' => 'otlphttp', 'settings' => []])->toArray();

        $operators = $merged['receivers']['filelog/vortos']['operators'];
        // Envelope parse → promote app line to body → parse app JSON into attributes.
        self::assertSame('json_parser', $operators[0]['type']);
        self::assertSame('move', $operators[1]['type']);
        self::assertSame('attributes.log', $operators[1]['from']);
        self::assertSame('body', $operators[1]['to']);
        self::assertSame('json_parser', $operators[2]['type']);
        self::assertSame('body', $operators[2]['parse_from']);
        // A non-JSON line must be kept, not dropped.
        self::assertSame('send_quiet', $operators[2]['on_error']);
        // The offset checkpoint survives restarts.
        self::assertSame('file_storage/vortos', $merged['receivers']['filelog/vortos']['storage']);
    }

    public function test_logs_pipeline_uses_persistent_file_storage_queue(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/log/app/*.json']);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => []])->toArray();

        $exporterKey = array_key_first($merged['exporters']);
        self::assertStringStartsWith('otlphttp/', $exporterKey);
        self::assertTrue($merged['exporters'][$exporterKey]['sending_queue']['enabled']);
        self::assertContains('file_storage/vortos', $merged['service']['extensions']);
    }

    public function test_sampling_processor_present_when_ratio_below_one(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/log/app/*.json'], sampleRatio: 0.25);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => []])->toArray();

        self::assertArrayHasKey('probabilistic_sampler/vortos_logs', $merged['processors']);
        self::assertSame(25.0, $merged['processors']['probabilistic_sampler/vortos_logs']['sampling_percentage']);
    }

    public function test_no_sampling_processor_when_ratio_is_one(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/log/app/*.json'], sampleRatio: 1.0);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => []])->toArray();

        self::assertArrayNotHasKey('probabilistic_sampler/vortos_logs', $merged['processors']);
    }

    public function test_redaction_renders_transform_processor_that_drops_keys_and_masks_values(): void
    {
        $config = (new LogRedactionPolicy())->toProcessorConfig();

        self::assertSame('ignore', $config['error_mode']);
        $statements = $config['log_statements'][0]['statements'];

        // Secret-named keys are deleted; secret-shaped values are masked in body + attributes.
        $joined = implode("\n", $statements);
        self::assertStringContainsString('delete_matching_keys(attributes,', $joined);
        self::assertStringContainsString('replace_pattern(body,', $joined);
        self::assertStringContainsString('replace_all_patterns(attributes, "value",', $joined);
        self::assertStringContainsString('***REDACTED***', $joined);
    }

    public function test_redaction_escapes_regex_backslashes_for_ottl(): void
    {
        // OTTL string literals need each PCRE backslash doubled (\d → \\d). A single backslash
        // would be an invalid OTTL escape and crash the collector at build time.
        $statements = (new LogRedactionPolicy(blockedValuePatterns: ['\\bAKIA[0-9A-Z]{16}\\b'], blockedKeyPatterns: []))
            ->toProcessorConfig()['log_statements'][0]['statements'];

        self::assertStringContainsString('\\\\bAKIA', implode("\n", $statements));
    }

    public function test_no_key_drop_statement_when_no_key_patterns(): void
    {
        $statements = (new LogRedactionPolicy(blockedKeyPatterns: []))->toProcessorConfig()['log_statements'][0]['statements'];

        foreach ($statements as $stmt) {
            self::assertStringNotContainsString('delete_matching_keys', $stmt);
        }
    }

    public function test_include_paths_must_not_be_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogPipelineConfig(includePaths: []);
    }

    public function test_sample_ratio_out_of_range_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogPipelineConfig(includePaths: ['/x'], sampleRatio: 1.5);
    }

    public function test_start_at_invalid_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogPipelineConfig(startAt: 'middle');
    }
}
