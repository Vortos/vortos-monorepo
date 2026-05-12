<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricOperation;
use Vortos\Observability\Telemetry\MetricResult;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Decorates TaggedCacheInterface to record per-operation cache metrics.
 *
 * ## Metrics recorded
 *
 *   vortos_cache_operations_total{operation, result}  — counter
 *     operation: get | set | delete | has | clear
 *     result:    hit | miss | ok (only get distinguishes hit/miss)
 *
 * ## Lazy iteration
 *
 *   getMultiple() yields results one by one — the inner adapter's generator is
 *   never materialized into a full array. setMultiple() and deleteMultiple()
 *   use a counting wrapper generator so the inner adapter receives an iterable
 *   and the count is known without loading everything into memory.
 */
final class CacheMetricsDecorator implements TaggedCacheInterface
{
    public function __construct(
        private readonly TaggedCacheInterface $inner,
        private readonly FrameworkTelemetry $telemetry,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->inner->get($key, $default);
        $this->record(MetricOperation::Get, $value !== $default ? MetricResult::Hit : MetricResult::Miss);
        return $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $result = $this->inner->set($key, $value, $ttl);
        $this->record(MetricOperation::Set, MetricResult::Ok);
        return $result;
    }

    public function delete(string $key): bool
    {
        $result = $this->inner->delete($key);
        $this->record(MetricOperation::Delete, MetricResult::Ok);
        return $result;
    }

    public function clear(): bool
    {
        $result = $this->inner->clear();
        $this->record(MetricOperation::Clear, MetricResult::Ok);
        return $result;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        foreach ($this->inner->getMultiple($keys, $default) as $key => $value) {
            $this->record(MetricOperation::Get, $value !== $default ? MetricResult::Hit : MetricResult::Miss);
            yield $key => $value;
        }
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $count   = 0;
        $counted = (static function () use ($values, &$count): \Generator {
            foreach ($values as $key => $value) {
                ++$count;
                yield $key => $value;
            }
        })();

        $result = $this->inner->setMultiple($counted, $ttl);

        if ($count > 0) {
            $this->record(MetricOperation::Set, MetricResult::Ok, (float) $count);
        }

        return $result;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        $count   = 0;
        $counted = (static function () use ($keys, &$count): \Generator {
            foreach ($keys as $key) {
                ++$count;
                yield $key;
            }
        })();

        $result = $this->inner->deleteMultiple($counted);

        if ($count > 0) {
            $this->record(MetricOperation::Delete, MetricResult::Ok, (float) $count);
        }

        return $result;
    }

    public function has(string $key): bool
    {
        $result = $this->inner->has($key);
        $this->record(MetricOperation::Has, $result ? MetricResult::Hit : MetricResult::Miss);
        return $result;
    }

    public function setWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): bool
    {
        $result = $this->inner->setWithTags($key, $value, $tags, $ttl);
        $this->record(MetricOperation::Set, MetricResult::Ok);
        return $result;
    }

    public function invalidateTags(array $tags): bool
    {
        $result = $this->inner->invalidateTags($tags);
        $this->record(MetricOperation::Delete, MetricResult::Ok);
        return $result;
    }

    private function record(MetricOperation $operation, MetricResult $result, float $by = 1.0): void
    {
        $this->telemetry->increment(
            ObservabilityModule::Cache,
            FrameworkMetric::CacheOperationsTotal,
            FrameworkMetricLabels::of(
                MetricLabelValue::operation($operation),
                MetricLabelValue::result($result),
            ),
            $by,
        );
    }
}
