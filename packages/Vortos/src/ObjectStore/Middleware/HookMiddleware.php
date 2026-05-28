<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Psr\Log\LoggerInterface;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreObserverInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;

#[AsObjectStoreMiddleware(priority: 700)]
final class HookMiddleware implements ObjectStoreMiddlewareInterface
{
    /** @param ObjectStoreObserverInterface[] $observers */
    public function __construct(
        private readonly array $observers,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        foreach ($this->observers as $observer) {
            $observer->before($operation);
        }

        try {
            $result = $next($operation);
        } catch (\Throwable $e) {
            foreach ($this->observers as $observer) {
                try {
                    $observer->failed($operation, $e);
                } catch (\Throwable $observerError) {
                    $this->logger->warning('object_store.observer_failed', ['error' => $observerError->getMessage()]);
                }
            }
            throw $e;
        }

        foreach ($this->observers as $observer) {
            try {
                $observer->after($operation, $result);
            } catch (\Throwable $observerError) {
                $this->logger->warning('object_store.observer_failed', ['error' => $observerError->getMessage()]);
            }
        }

        return $result;
    }
}
