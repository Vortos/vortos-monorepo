<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Testing;

use PHPUnit\Framework\Assert;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\ValueObject\BulkDeleteResult;
use Vortos\ObjectStore\ValueObject\ContentType;
use Vortos\ObjectStore\ValueObject\CopyObjectOptions;
use Vortos\ObjectStore\ValueObject\DeleteResult;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;
use Vortos\ObjectStore\ValueObject\HttpMethod;
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

final class ObjectStoreFake implements ObjectStoreInterface
{
    /** @var array<string, array{body: string, content_type: ?string, metadata: array<string, string>, updated_at: \DateTimeImmutable}> */
    private array $objects = [];

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);
        $contents = $body->contents();
        $options ??= PutObjectOptions::default();

        $this->objects[$key->value()] = [
            'body' => $contents,
            'content_type' => $options->contentType()?->value(),
            'metadata' => $options->metadata(),
            'updated_at' => new \DateTimeImmutable(),
        ];

        return new StoredObject($key, md5($contents), strlen($contents));
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        $key = ObjectKey::from($key);
        $row = $this->object($key);
        $body = $row['body'];

        if ($options?->range() !== null) {
            $start = $options->range()->start();
            $end = $options->range()->end();
            $body = substr($body, $start, ($end ?? strlen($body) - 1) - $start + 1);
        }

        return ObjectBody::from($body);
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $this->get($key, $options)->contents());
        rewind($stream);

        return $stream;
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        $key = ObjectKey::from($key);
        $row = $this->object($key);

        return new ObjectMetadata(
            $key,
            strlen($row['body']),
            ContentType::from($row['content_type']),
            md5($row['body']),
            $row['updated_at'],
            $row['metadata'],
        );
    }

    public function exists(ObjectKey|string $key): bool
    {
        return isset($this->objects[ObjectKey::from($key)->value()]);
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        $key = ObjectKey::from($key);
        $deleted = isset($this->objects[$key->value()]);
        unset($this->objects[$key->value()]);

        return new DeleteResult($key, $deleted);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return new BulkDeleteResult(array_map(fn(ObjectKey|string $key): DeleteResult => $this->delete($key), $keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $source = ObjectKey::from($source);
        $target = ObjectKey::from($target);
        $this->objects[$target->value()] = $this->object($source);

        return new StoredObject($target, md5($this->objects[$target->value()]['body']), strlen($this->objects[$target->value()]['body']));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $stored = $this->copy($source, $target, $options);
        $this->delete($source);

        return $stored;
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        $options ??= new ListObjectsOptions();
        $rows = [];

        foreach ($this->objects as $key => $row) {
            if ($options->prefix() !== null && !str_starts_with($key, $options->prefix())) {
                continue;
            }

            $rows[] = new ListedObject(new ObjectKey($key), strlen($row['body']), md5($row['body']), $row['updated_at']);
        }

        usort($rows, static fn(ListedObject $a, ListedObject $b): int => strcmp($a->key()->value(), $b->key()->value()));

        return new ObjectListing(array_slice($rows, 0, $options->maxKeys()), null, count($rows) > $options->maxKeys());
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return new PresignedUrl('https://object-store.fake/' . rawurlencode(ObjectKey::from($key)->value()), HttpMethod::Get, $expiresAt);
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return new PresignedUploadUrl(
            ObjectKey::from($key),
            new PresignedUrl('https://object-store.fake/' . rawurlencode(ObjectKey::from($key)->value()), HttpMethod::Put, $options->expiresAt(), $options->constraints()->requiredHeaders()),
            $options->constraints(),
        );
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        $key = ObjectKey::from($key);

        return new PresignedPostPolicy($key, 'https://object-store.fake/', $options->expiresAt(), $options->constraints(), ['key' => $key->value()]);
    }

    public function assertExists(ObjectKey|string $key): void
    {
        Assert::assertTrue($this->exists($key), sprintf('Failed asserting object "%s" exists.', ObjectKey::from($key)->value()));
    }

    public function assertMissing(ObjectKey|string $key): void
    {
        Assert::assertFalse($this->exists($key), sprintf('Failed asserting object "%s" is missing.', ObjectKey::from($key)->value()));
    }

    public function assertContents(ObjectKey|string $key, string $expected): void
    {
        Assert::assertSame($expected, $this->get($key)->contents());
    }

    /** @return array{body: string, content_type: ?string, metadata: array<string, string>, updated_at: \DateTimeImmutable} */
    private function object(ObjectKey $key): array
    {
        return $this->objects[$key->value()] ?? throw ObjectNotFoundException::forKey($key->value());
    }
}
