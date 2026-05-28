<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Contract;

use Vortos\ObjectStore\ValueObject\BulkDeleteResult;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\ListObjectsOptions;
use Vortos\ObjectStore\ValueObject\ObjectBody;
use Vortos\ObjectStore\ValueObject\ObjectKey;
use Vortos\ObjectStore\ValueObject\ObjectListing;
use Vortos\ObjectStore\ValueObject\ObjectMetadata;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;
use Vortos\ObjectStore\ValueObject\StoredObject;

/**
 * Primary application object-store contract.
 *
 * With outbox enabled, mutating operations are transactional: they write to the
 * object-store outbox and require an active CommandBus/UnitOfWork transaction.
 * Use StandaloneObjectStoreInterface for standalone async outbox operations and
 * ImmediateObjectStoreInterface for direct provider calls with no outbox.
 */
interface ObjectStoreInterface extends PresignedUrlGeneratorInterface
{
    /** @param resource|string|ObjectBody $body */
    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject;

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody;

    /** @return resource */
    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed;

    public function head(ObjectKey|string $key): ObjectMetadata;

    public function exists(ObjectKey|string $key): bool;

    public function delete(ObjectKey|string $key): DeleteResult;

    /** @param array<int, ObjectKey|string> $keys */
    public function deleteMany(array $keys): BulkDeleteResult;

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject;

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject;

    public function list(?ListObjectsOptions $options = null): ObjectListing;
}
