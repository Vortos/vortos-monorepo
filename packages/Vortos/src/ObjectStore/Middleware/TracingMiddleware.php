<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\Tracing\Contract\TracingInterface;

#[AsObjectStoreMiddleware(priority: 800)]
final class TracingMiddleware implements ObjectStoreMiddlewareInterface
{
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly TracingInterface $tracer,
        private readonly array $disabledSections = [],
    ) {}

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        if (in_array(ObjectStoreObservabilitySection::fromOperation($operation->name())->value, $this->disabledSections, true)) {
            return $next($operation);
        }

        $span = $this->tracer->startSpan('object_store.' . $operation->name(), [
            'object_store.operation' => $operation->name(),
        ]);

        try {
            $result = $next($operation);
            $span->setStatus('ok');
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus('error');
            throw $e;
        } finally {
            $span->end();
        }
    }
}
