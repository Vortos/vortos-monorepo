<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Vortos\Metrics\Contract\MetricsInterface;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;

#[AsObjectStoreMiddleware(priority: 650)]
final class MetricsMiddleware implements ObjectStoreMiddlewareInterface
{
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly MetricsInterface $metrics,
        private readonly array $disabledSections = [],
    ) {}

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        if (in_array(ObjectStoreObservabilitySection::fromOperation($operation->name())->value, $this->disabledSections, true)) {
            return $next($operation);
        }

        $start = hrtime(true);

        try {
            $result = $next($operation);
            $this->metrics->counter('vortos_object_store_operations_total', [
                'operation' => $operation->name(),
                'status' => 'success',
            ])->increment();
            return $result;
        } catch (\Throwable $e) {
            $this->metrics->counter('vortos_object_store_operations_total', [
                'operation' => $operation->name(),
                'status' => 'failure',
            ])->increment();
            throw $e;
        } finally {
            $this->metrics->histogram('vortos_object_store_operation_duration_ms', [
                'operation' => $operation->name(),
            ])->observe(round((hrtime(true) - $start) / 1_000_000, 2));
        }
    }
}
