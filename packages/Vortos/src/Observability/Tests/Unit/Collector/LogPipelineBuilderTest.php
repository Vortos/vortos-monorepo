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
        self::assertArrayHasKey('redaction/vortos', $merged['processors']);
        self::assertSame(['filelog/vortos'], $merged['service']['pipelines']['logs']['receivers']);
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

    public function test_info_sampling_processor_present_when_ratio_below_one(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/log/app/*.json'], infoSampleRatio: 0.25);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => []])->toArray();

        self::assertArrayHasKey('probabilistic_sampler/vortos_logs', $merged['processors']);
        self::assertSame(25.0, $merged['processors']['probabilistic_sampler/vortos_logs']['sampling_percentage']);
    }

    public function test_no_sampling_processor_when_ratio_is_one(): void
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(includePaths: ['/var/log/app/*.json'], infoSampleRatio: 1.0);

        $merged = $builder->merge($this->baseConfig(), $logConfig, ['type' => 'otlphttp', 'settings' => []])->toArray();

        self::assertArrayNotHasKey('probabilistic_sampler/vortos_logs', $merged['processors']);
    }

    public function test_redaction_policy_blocks_known_secret_patterns(): void
    {
        $policy = new LogRedactionPolicy();
        $config = $policy->toProcessorConfig();

        self::assertFalse($config['allow_all_keys']);
        self::assertNotEmpty($config['blocked_values']);
        // A known secret token must match at least one deny pattern.
        $matched = false;
        foreach ($config['blocked_values'] as $pattern) {
            if (preg_match('#' . $pattern . '#', 'password=supersecretvalue1234567890') === 1) {
                $matched = true;
                break;
            }
        }
        self::assertTrue($matched, 'Expected at least one deny pattern to match a password= token.');
    }

    public function test_include_paths_must_not_be_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogPipelineConfig(includePaths: []);
    }

    public function test_sample_ratio_out_of_range_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogPipelineConfig(includePaths: ['/x'], infoSampleRatio: 1.5);
    }
}
