<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver;

use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
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

final class ImmediateObjectStore implements ImmediateObjectStoreInterface
{
    public function __construct(private readonly ObjectStoreInterface $inner) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        return $this->inner->put($key, $body, $options);
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return $this->inner->get($key, $options);
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        return $this->inner->stream($key, $options);
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        return $this->inner->head($key);
    }

    public function exists(ObjectKey|string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        return $this->inner->delete($key);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return $this->inner->deleteMany($keys);
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->inner->copy($source, $target, $options);
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->inner->move($source, $target, $options);
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return $this->inner->list($options);
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return $this->inner->temporaryDownloadUrl($key, $expiresAt, $options);
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return $this->inner->temporaryUploadUrl($key, $options);
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        return $this->inner->temporaryPostUpload($key, $options);
    }
}
