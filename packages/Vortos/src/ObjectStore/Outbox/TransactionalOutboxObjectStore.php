<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Outbox;

use Vortos\ObjectStore\Contract\ObjectOutboxWriterInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\ValueObject\BulkDeleteResult;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\HttpMethod;
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

final class TransactionalOutboxObjectStore implements ObjectStoreInterface
{
    public function __construct(
        private readonly ObjectOutboxWriterInterface $writer,
        private readonly ObjectStoreInterface $reader,
    ) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Put, ['key' => $key, 'body' => $body, 'options' => $options]));

        return new StoredObject($key, null, $body->size() ?? 0);
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return $this->reader->get($key, $options);
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        return $this->reader->stream($key, $options);
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        return $this->reader->head($key);
    }

    public function exists(ObjectKey|string $key): bool
    {
        return $this->reader->exists($key);
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        $key = ObjectKey::from($key);
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Delete, ['key' => $key]));

        return new DeleteResult($key, false);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return new BulkDeleteResult(array_map(fn($key): DeleteResult => $this->delete($key), $keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $source = ObjectKey::from($source);
        $target = ObjectKey::from($target);
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Copy, ['source' => $source, 'target' => $target, 'options' => $options]));

        return new StoredObject($target, null, 0);
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $source = ObjectKey::from($source);
        $target = ObjectKey::from($target);
        $this->writer->queue(new ObjectStoreOperation(ObjectStoreOperationName::Move, ['source' => $source, 'target' => $target, 'options' => $options]));

        return new StoredObject($target, null, 0);
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return $this->reader->list($options);
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return $this->reader->temporaryDownloadUrl($key, $expiresAt, $options);
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return $this->reader->temporaryUploadUrl($key, $options);
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        return $this->reader->temporaryPostUpload($key, $options);
    }
}
