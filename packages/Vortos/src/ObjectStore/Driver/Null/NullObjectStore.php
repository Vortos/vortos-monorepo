<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver\Null;

use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
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

class NullObjectStore implements ObjectStoreInterface
{
    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);

        return new StoredObject($key, null, $body->size() ?? 0);
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        throw ObjectNotFoundException::forKey((string) ObjectKey::from($key));
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        throw ObjectNotFoundException::forKey((string) ObjectKey::from($key));
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        throw ObjectNotFoundException::forKey((string) ObjectKey::from($key));
    }

    public function exists(ObjectKey|string $key): bool
    {
        ObjectKey::from($key);
        return false;
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        return new DeleteResult(ObjectKey::from($key), false);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return new BulkDeleteResult(array_map(fn($key) => $this->delete($key), $keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        throw ObjectNotFoundException::forKey((string) ObjectKey::from($source));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        throw ObjectNotFoundException::forKey((string) ObjectKey::from($source));
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return new ObjectListing([]);
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        $key = ObjectKey::from($key);

        return new PresignedUrl(
            'https://object-store.invalid/' . rawurlencode($key->value()),
            HttpMethod::Get,
            $expiresAt,
        );
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        $key = ObjectKey::from($key);

        return new PresignedUploadUrl(
            $key,
            new PresignedUrl(
                'https://object-store.invalid/' . rawurlencode($key->value()),
                HttpMethod::Put,
                $options->expiresAt(),
                $options->constraints()->requiredHeaders(),
            ),
            $options->constraints(),
        );
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        $key = ObjectKey::from($key);

        return new PresignedPostPolicy(
            $key,
            'https://object-store.invalid/',
            $options->expiresAt(),
            $options->constraints(),
            ['key' => $key->value()],
        );
    }
}
