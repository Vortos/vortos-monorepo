<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Config\ObjectStoreObservabilitySection;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\ValueObject\ObjectKey;

#[AsObjectStoreMiddleware(priority: 100)]
final class LoggingMiddleware implements ObjectStoreMiddlewareInterface
{
    /** @param string[] $disabledSections */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $disabledSections = [],
    ) {}

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        if (in_array(ObjectStoreObservabilitySection::fromOperation($operation->name())->value, $this->disabledSections, true)) {
            return $next($operation);
        }

        try {
            $result = $next($operation);
        } catch (\Throwable $e) {
            $this->logger->warning('object_store.operation_failed', [
                'operation' => $operation->name(),
                'key' => $this->keyFromContext($operation->context()),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('object_store.operation_succeeded', [
            'operation' => $operation->name(),
            'key' => $this->keyFromContext($operation->context()),
        ]);

        return $result;
    }

    private function keyFromContext(array $context): ?string
    {
        $key = $context['key'] ?? $context['target'] ?? $context['source'] ?? null;

        return $key instanceof ObjectKey ? $key->value() : null;
    }
}
