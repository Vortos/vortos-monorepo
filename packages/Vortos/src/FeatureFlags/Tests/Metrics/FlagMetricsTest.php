<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\FeatureFlags\Metrics\InstrumentedFlagRegistry;
use Vortos\FeatureFlags\Tests\Support\RecordingMetrics;

final class FlagMetricsTest extends TestCase
{
    /** Bounded label keys — never an identifier. Pins the cardinality contract. */
    private const ALLOWED_LABEL_KEYS = ['flag', 'result', 'variant', 'operation'];

    public function test_metrics_helper_is_zero_cost_with_no_backend(): void
    {
        $metrics = new FlagEvaluationMetrics(null);

        // Must not throw or error when no MetricsInterface is wired.
        $metrics->evaluation('f', FlagEvaluationMetrics::RESULT_ON);
        $metrics->variantAssignment('f', 'treatment');
        $metrics->duration('is_enabled', 1.2);
        $metrics->exposure('f', 'treatment');

        $this->assertTrue(true);
    }

    public function test_evaluation_emits_counter_with_bounded_labels(): void
    {
        $sink    = new RecordingMetrics();
        $metrics = new FlagEvaluationMetrics($sink);

        $metrics->evaluation('dark-mode', FlagEvaluationMetrics::RESULT_ON);

        $this->assertCount(1, $sink->counters);
        $this->assertSame(FlagEvaluationMetrics::EVALUATIONS, $sink->counters[0]['name']);
        $this->assertSame(['flag' => 'dark-mode', 'result' => 'on'], $sink->counters[0]['labels']);
        $this->assertLabelKeysBounded($sink);
    }

    public function test_exposure_and_variant_labels_are_bounded(): void
    {
        $sink    = new RecordingMetrics();
        $metrics = new FlagEvaluationMetrics($sink);

        $metrics->exposure('exp', 'treatment-b');
        $metrics->variantAssignment('exp', 'treatment-b');
        $metrics->duration('all_for_context', 3.5);

        $this->assertLabelKeysBounded($sink);
        $this->assertCount(1, $sink->histograms);
        $this->assertSame(['operation' => 'all_for_context'], $sink->histograms[0]['labels']);
    }

    public function test_instrumented_registry_is_transparent_and_records(): void
    {
        $inner = new class implements FlagRegistryInterface {
            public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
            {
                return $name === 'on-flag';
            }

            public function variant(string $name, FlagContext $context = new FlagContext()): string
            {
                return $name === 'exp' ? 'treatment' : 'control';
            }

            public function allForContext(FlagContext $context = new FlagContext()): array
            {
                return ['flags' => ['on-flag'], 'variants' => [], 'payloads' => [], 'version' => 'v1:test'];
            }
        };

        $sink         = new RecordingMetrics();
        $instrumented = new InstrumentedFlagRegistry($inner, new FlagEvaluationMetrics($sink));

        // Transparency: identical results to the inner registry.
        $this->assertTrue($instrumented->isEnabled('on-flag'));
        $this->assertFalse($instrumented->isEnabled('off-flag'));
        $this->assertSame('treatment', $instrumented->variant('exp'));
        $this->assertSame('control', $instrumented->variant('plain'));
        $this->assertSame(['on-flag'], $instrumented->allForContext()['flags']);

        // Recorded: 2 evaluations (on + off) + 1 variant assignment (control skipped).
        $evaluations = array_filter($sink->counters, fn($c) => $c['name'] === FlagEvaluationMetrics::EVALUATIONS);
        $variants    = array_filter($sink->counters, fn($c) => $c['name'] === FlagEvaluationMetrics::VARIANT_ASSIGNMENTS);
        $this->assertCount(2, $evaluations);
        $this->assertCount(1, $variants);
        $this->assertLabelKeysBounded($sink);
    }

    private function assertLabelKeysBounded(RecordingMetrics $sink): void
    {
        foreach ([...$sink->counters, ...$sink->histograms] as $metric) {
            foreach (array_keys($metric['labels']) as $key) {
                $this->assertContains(
                    $key,
                    self::ALLOWED_LABEL_KEYS,
                    "High-cardinality label key '$key' leaked into a flag metric",
                );
            }
        }
    }

    private function flag(string $name): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag('id', $name, '', true, [], null, $now, $now);
    }
}
