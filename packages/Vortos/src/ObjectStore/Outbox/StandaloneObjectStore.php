<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Doctrine\DBAL\Connection;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\StandaloneObjectStoreInterface;
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

final class StandaloneObjectStore implements StandaloneObjectStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ObjectStoreInterface $transactionalStore,
    ) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        return $this->write(fn(): StoredObject => $this->transactionalStore->put($key, $body, $options));
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return $this->transactionalStore->get($key, $options);
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        return $this->transactionalStore->stream($key, $options);
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        return $this->transactionalStore->head($key);
    }

    public function exists(ObjectKey|string $key): bool
    {
        return $this->transactionalStore->exists($key);
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        return $this->write(fn(): DeleteResult => $this->transactionalStore->delete($key));
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return $this->write(fn(): BulkDeleteResult => $this->transactionalStore->deleteMany($keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->write(fn(): StoredObject => $this->transactionalStore->copy($source, $target, $options));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->write(fn(): StoredObject => $this->transactionalStore->move($source, $target, $options));
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return $this->transactionalStore->list($options);
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return $this->transactionalStore->temporaryDownloadUrl($key, $expiresAt, $options);
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return $this->transactionalStore->temporaryUploadUrl($key, $options);
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        return $this->transactionalStore->temporaryPostUpload($key, $options);
    }

    private function write(callable $operation): mixed
    {
        if ($this->connection->isTransactionActive()) {
            return $operation();
        }

        return $this->connection->transactional($operation);
    }
}
