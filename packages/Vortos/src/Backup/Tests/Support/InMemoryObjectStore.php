<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use DateTimeImmutable;
use RuntimeException;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\ValueObject\BulkDeleteResult;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\ListedObject;
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

/**
 * A minimal in-memory object store for testing the backup store driver. It reads the
 * resource body fully (so the checksum read-filter runs, exactly as a real PHP-side
 * store does) and keeps bytes in a map.
 *
 * @internal test support
 */
final class InMemoryObjectStore implements ObjectStoreInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    /** Inject a failure after N bytes to simulate a mid-upload store error. */
    public ?int $failAfterBytes = null;

    /**
     * Make the next N reads throw, as a throttled or briefly unavailable store does.
     *
     * Modelled on a real production failure: a point-in-time drill served ~325 WAL segments and then
     * took a single 403 from R2 on an object that read back perfectly a second later.
     */
    public int $failOpenTimes = 0;

    /**
     * Keys the store will refuse to delete, as a WORM bucket does. The message mirrors R2's own
     * wording ("The object is locked by the bucket policy") so the immutability matcher is tested
     * against the string production actually returns rather than an invented one.
     *
     * @var list<string>
     */
    public array $immutableKeys = [];

    /** When set, every delete throws with this message — for asserting non-immutability failures. */
    public ?string $deleteError = null;

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $name = (string) $key;
        $data = $this->readBody($body);

        if ($this->failAfterBytes !== null && strlen($data) >= $this->failAfterBytes) {
            // Simulate a partial write that then fails.
            $this->objects[$name] = substr($data, 0, $this->failAfterBytes);
            throw new RuntimeException('Simulated store failure mid-upload.');
        }

        $this->objects[$name] = $data;

        return new StoredObject(ObjectKey::from($name), md5($data), strlen($data));
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return ObjectBody::from($this->mustGet((string) $key));
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        if ($this->failOpenTimes > 0) {
            $this->failOpenTimes--;

            throw new RuntimeException('r2 object store error: 403 Forbidden');
        }

        $resource = fopen('php://temp', 'r+b');
        if ($resource === false) {
            throw new RuntimeException('Cannot open temp stream.');
        }
        fwrite($resource, $this->mustGet((string) $key));
        rewind($resource);

        return $resource;
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        $data = $this->mustGet((string) $key);

        return new ObjectMetadata(ObjectKey::from((string) $key), strlen($data), null, md5($data), new DateTimeImmutable('now'));
    }

    public function exists(ObjectKey|string $key): bool
    {
        return isset($this->objects[(string) $key]);
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        $name = (string) $key;

        if ($this->deleteError !== null) {
            throw new RuntimeException($this->deleteError);
        }

        if (in_array($name, $this->immutableKeys, true)) {
            throw new RuntimeException('r2 object store error: The object is locked by the bucket policy.');
        }

        $existed = isset($this->objects[$name]);
        unset($this->objects[$name]);

        return new DeleteResult(ObjectKey::from($name), $existed);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        $results = [];
        foreach ($keys as $key) {
            $results[] = $this->delete($key);
        }

        return new BulkDeleteResult($results);
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $data = $this->mustGet((string) $source);
        $this->objects[(string) $target] = $data;

        return new StoredObject(ObjectKey::from((string) $target), md5($data), strlen($data));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $stored = $this->copy($source, $target, $options);
        $this->delete($source);

        return $stored;
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        $prefix = $options?->prefix() ?? '';
        $objects = [];
        foreach ($this->objects as $name => $data) {
            if ($prefix === '' || str_starts_with($name, $prefix)) {
                $objects[] = new ListedObject(ObjectKey::from($name), strlen($data));
            }
        }

        return new ObjectListing($objects, null, false);
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        throw new RuntimeException('Not supported in test double.');
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        throw new RuntimeException('Not supported in test double.');
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        throw new RuntimeException('Not supported in test double.');
    }

    /** @param resource|string|ObjectBody $body */
    private function readBody(mixed $body): string
    {
        if ($body instanceof ObjectBody) {
            return $body->contents();
        }
        if (is_resource($body)) {
            return (string) stream_get_contents($body);
        }

        return (string) $body;
    }

    private function mustGet(string $key): string
    {
        return $this->objects[$key] ?? throw new RuntimeException("No such object: {$key}");
    }
}
