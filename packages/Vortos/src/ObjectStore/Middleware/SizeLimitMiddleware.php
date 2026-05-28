<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Exception\ObjectTooLargeException;
use Vortos\ObjectStore\ValueObject\ObjectBody;

#[AsObjectStoreMiddleware(priority: 900)]
final class SizeLimitMiddleware implements ObjectStoreMiddlewareInterface
{
    public function __construct(private readonly int $maxUploadSizeBytes)
    {
    }

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        if ($operation->name() === 'put') {
            $body = $operation->context()['body'] ?? null;
            if ($body instanceof ObjectBody && $body->size() !== null && $body->size() > $this->maxUploadSizeBytes) {
                throw ObjectTooLargeException::forSize($body->size(), $this->maxUploadSizeBytes);
            }
        }

        return $next($operation);
    }
}
