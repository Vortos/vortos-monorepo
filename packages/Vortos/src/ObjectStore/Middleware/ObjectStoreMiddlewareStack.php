<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\ValueObject\BulkDeleteResult;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\ListObjectsOptions;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\ObjectListing;
use Vortos\ObjectStore\ValueObject\ObjectMetadata;
use Vortos\ObjectStore\ValueObject\PresignedPostPolicy;
use Vortos\ObjectStore\ValueObject\PresignedUploadUrl;
use Vortos\ObjectStore\ValueObject\PresignedUrl;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\StoredObject;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

final class ObjectStoreMiddlewareStack implements ObjectStoreInterface
{
    /** @param ObjectStoreMiddlewareInterface[] $middlewares */
    public function __construct(
        private readonly ObjectStoreInterface $driver,
        private readonly array $middlewares = [],
    ) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        return $this->run(ObjectStoreOperationName::Put, ['key' => ObjectKey::from($key), 'body' => ObjectBody::from($body), 'options' => $options], fn() => $this->driver->put($key, $body, $options));
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return $this->run(ObjectStoreOperationName::Get, ['key' => ObjectKey::from($key), 'options' => $options], fn() => $this->driver->get($key, $options));
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        return $this->run(ObjectStoreOperationName::Stream, ['key' => ObjectKey::from($key), 'options' => $options], fn() => $this->driver->stream($key, $options));
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        return $this->run(ObjectStoreOperationName::Head, ['key' => ObjectKey::from($key)], fn() => $this->driver->head($key));
    }

    public function exists(ObjectKey|string $key): bool
    {
        return $this->run(ObjectStoreOperationName::Exists, ['key' => ObjectKey::from($key)], fn() => $this->driver->exists($key));
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        return $this->run(ObjectStoreOperationName::Delete, ['key' => ObjectKey::from($key)], fn() => $this->driver->delete($key));
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return $this->run(ObjectStoreOperationName::DeleteMany, ['keys' => array_map(static fn($key): ObjectKey => ObjectKey::from($key), $keys)], fn() => $this->driver->deleteMany($keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->run(ObjectStoreOperationName::Copy, ['source' => ObjectKey::from($source), 'target' => ObjectKey::from($target), 'options' => $options], fn() => $this->driver->copy($source, $target, $options));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->run(ObjectStoreOperationName::Move, ['source' => ObjectKey::from($source), 'target' => ObjectKey::from($target), 'options' => $options], fn() => $this->driver->move($source, $target, $options));
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return $this->run(ObjectStoreOperationName::List, ['options' => $options], fn() => $this->driver->list($options));
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return $this->run(ObjectStoreOperationName::TemporaryDownloadUrl, ['key' => ObjectKey::from($key), 'expires_at' => $expiresAt, 'options' => $options], fn() => $this->driver->temporaryDownloadUrl($key, $expiresAt, $options));
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return $this->run(ObjectStoreOperationName::TemporaryUploadUrl, ['key' => ObjectKey::from($key), 'options' => $options], fn() => $this->driver->temporaryUploadUrl($key, $options));
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        return $this->run(ObjectStoreOperationName::TemporaryPostUpload, ['key' => ObjectKey::from($key), 'options' => $options], fn() => $this->driver->temporaryPostUpload($key, $options));
    }

    private function run(ObjectStoreOperationName $name, array $context, callable $driverCall): mixed
    {
        $operation = new ObjectStoreOperation($name, $context);
        $chain = fn(ObjectStoreOperation $op): mixed => $driverCall();

        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $chain;
            $chain = static fn(ObjectStoreOperation $op): mixed => $middleware->process($op, $next);
        }

        return $chain($operation);
    }
}
