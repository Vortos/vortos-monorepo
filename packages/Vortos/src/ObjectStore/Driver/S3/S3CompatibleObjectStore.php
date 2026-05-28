<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver\S3;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Exception\BucketNotFoundException;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\Exception\ObjectStoreAccessDeniedException;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\Exception\ObjectStoreRateLimitException;
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

final class S3CompatibleObjectStore implements ObjectStoreInterface
{
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $provider = 'generic_s3',
    ) {
        if ($bucket === '') {
            throw new \InvalidArgumentException('Object store bucket cannot be empty for the S3 driver.');
        }
    }

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        $key = ObjectKey::from($key);
        $body = ObjectBody::from($body);
        $options ??= PutObjectOptions::default();

        $request = [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
            'Body' => $body->raw(),
        ];

        if ($options->contentType() !== null) {
            $request['ContentType'] = $options->contentType()->value();
        }

        if ($options->metadata() !== []) {
            $request['Metadata'] = $options->metadata();
        }

        if ($options->cacheControl() !== null) {
            $request['CacheControl'] = $options->cacheControl();
        }

        if ($options->contentDisposition() !== null) {
            $request['ContentDisposition'] = $options->contentDisposition();
        }

        try {
            $result = $this->client->putObject($request);
        } catch (AwsException $e) {
            $this->mapException($e, $key);
        }

        return new StoredObject(
            key: $key,
            etag: $this->normalizeEtag($result['ETag'] ?? null),
            size: $body->size() ?? 0,
            versionId: isset($result['VersionId']) ? (string) $result['VersionId'] : null,
        );
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        $key = ObjectKey::from($key);

        try {
            $result = $this->client->getObject($this->objectRequest($key, $options));
        } catch (AwsException $e) {
            $this->mapException($e, $key);
        }

        return ObjectBody::from($this->bodyToString($result['Body'] ?? ''));
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        $body = $this->get($key, $options);
        $stream = fopen('php://temp', 'rb+');
        fwrite($stream, $body->contents());
        rewind($stream);

        return $stream;
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        $key = ObjectKey::from($key);

        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
            ]);
        } catch (AwsException $e) {
            $this->mapException($e, $key);
        }

        $lastModified = $result['LastModified'] ?? null;
        if ($lastModified instanceof \DateTimeInterface && !$lastModified instanceof \DateTimeImmutable) {
            $lastModified = \DateTimeImmutable::createFromInterface($lastModified);
        }

        return new ObjectMetadata(
            key: $key,
            size: (int) ($result['ContentLength'] ?? 0),
            contentType: ContentType::from(isset($result['ContentType']) ? (string) $result['ContentType'] : null),
            etag: $this->normalizeEtag($result['ETag'] ?? null),
            lastModified: $lastModified instanceof \DateTimeImmutable ? $lastModified : null,
            metadata: is_array($result['Metadata'] ?? null) ? $result['Metadata'] : [],
        );
    }

    public function exists(ObjectKey|string $key): bool
    {
        try {
            $this->head($key);
            return true;
        } catch (ObjectNotFoundException) {
            return false;
        }
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        $key = ObjectKey::from($key);

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key->value(),
            ]);
        } catch (AwsException $e) {
            $this->mapException($e, $key);
        }

        return new DeleteResult($key, true);
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        if ($keys === []) {
            return new BulkDeleteResult([]);
        }

        $objectKeys = array_map(static fn($key): ObjectKey => ObjectKey::from($key), $keys);

        try {
            $result = $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => [
                    'Objects' => array_map(
                        static fn(ObjectKey $key): array => ['Key' => $key->value()],
                        $objectKeys,
                    ),
                    'Quiet' => false,
                ],
            ]);
        } catch (AwsException $e) {
            $this->mapException($e);
        }

        $failed = [];
        foreach (($result['Errors'] ?? []) as $error) {
            if (isset($error['Key'])) {
                $failed[(string) $error['Key']] = true;
            }
        }

        return new BulkDeleteResult(array_map(
            static fn(ObjectKey $key): DeleteResult => new DeleteResult($key, !isset($failed[$key->value()])),
            $objectKeys,
        ));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        $source = ObjectKey::from($source);
        $target = ObjectKey::from($target);
        $options ??= new CopyObjectOptions();

        $request = [
            'Bucket' => $this->bucket,
            'Key' => $target->value(),
            'CopySource' => rawurlencode($this->bucket . '/' . $source->value()),
        ];

        if ($options->metadata() !== []) {
            $request['Metadata'] = $options->metadata();
            $request['MetadataDirective'] = $options->replaceMetadata() ? 'REPLACE' : 'COPY';
        }

        try {
            $result = $this->client->copyObject($request);
        } catch (AwsException $e) {
            $this->mapException($e, $source);
        }

        return new StoredObject(
            $target,
            $this->normalizeEtag($result['CopyObjectResult']['ETag'] ?? $result['ETag'] ?? null),
            0,
            isset($result['VersionId']) ? (string) $result['VersionId'] : null,
        );
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

        $request = [
            'Bucket' => $this->bucket,
            'MaxKeys' => $options->maxKeys(),
        ];

        if ($options->prefix() !== null) {
            $request['Prefix'] = $options->prefix();
        }

        if ($options->delimiter() !== null) {
            $request['Delimiter'] = $options->delimiter();
        }

        if ($options->continuationToken() !== null) {
            $request['ContinuationToken'] = $options->continuationToken();
        }

        try {
            $result = $this->client->listObjectsV2($request);
        } catch (AwsException $e) {
            $this->mapException($e);
        }

        $objects = [];
        foreach (($result['Contents'] ?? []) as $row) {
            $lastModified = $row['LastModified'] ?? null;
            if ($lastModified instanceof \DateTimeInterface && !$lastModified instanceof \DateTimeImmutable) {
                $lastModified = \DateTimeImmutable::createFromInterface($lastModified);
            }

            $objects[] = new ListedObject(
                new ObjectKey((string) $row['Key']),
                (int) ($row['Size'] ?? 0),
                $this->normalizeEtag($row['ETag'] ?? null),
                $lastModified instanceof \DateTimeImmutable ? $lastModified : null,
            );
        }

        return new ObjectListing(
            $objects,
            isset($result['NextContinuationToken']) ? (string) $result['NextContinuationToken'] : null,
            (bool) ($result['IsTruncated'] ?? false),
        );
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        $key = ObjectKey::from($key);
        $command = $this->client->getCommand('GetObject', $this->objectRequest($key, $options));

        return $this->presign($command, HttpMethod::Get, $expiresAt);
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        $key = ObjectKey::from($key);
        $request = [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
        ];

        if ($options->constraints()->contentType() !== null) {
            $request['ContentType'] = $options->constraints()->contentType()->value();
        }

        if ($options->metadata() !== []) {
            $request['Metadata'] = $options->metadata();
        }

        $command = $this->client->getCommand('PutObject', $request);
        $url = $this->presign($command, HttpMethod::Put, $options->expiresAt(), $options->constraints()->requiredHeaders());

        return new PresignedUploadUrl($key, $url, $options->constraints());
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        $key = ObjectKey::from($key);

        $inputs = ['key' => $key->value()];
        $conditions = [
            ['bucket' => $this->bucket],
            ['key' => $key->value()],
            $options->constraints()->postPolicyContentLengthRange(),
        ];

        if ($options->constraints()->contentType() !== null) {
            $inputs['Content-Type'] = $options->constraints()->contentType()->value();
            $conditions[] = ['Content-Type' => $options->constraints()->contentType()->value()];
        }

        foreach ($options->metadata() as $name => $value) {
            $field = 'x-amz-meta-' . strtolower($name);
            $inputs[$field] = $value;
            $conditions[] = [$field => $value];
        }

        $post = new PostObjectV4($this->client, $this->bucket, $inputs, $conditions, $options->expiresAt());

        return new PresignedPostPolicy(
            $key,
            $post->getFormAttributes()['action'],
            $options->expiresAt(),
            $options->constraints(),
            $post->getFormInputs(),
        );
    }

    private function objectRequest(ObjectKey $key, ?GetObjectOptions $options = null): array
    {
        $request = [
            'Bucket' => $this->bucket,
            'Key' => $key->value(),
        ];

        if ($options?->range() !== null) {
            $request['Range'] = $options->range()->headerValue();
        }

        return $request;
    }

    private function presign(
        CommandInterface $command,
        HttpMethod $method,
        \DateTimeImmutable $expiresAt,
        array $headers = [],
    ): PresignedUrl {
        $request = $this->client->createPresignedRequest($command, $expiresAt);

        return new PresignedUrl(
            (string) $request->getUri(),
            $method,
            $expiresAt,
            $headers,
        );
    }

    private function normalizeEtag(mixed $etag): ?string
    {
        return $etag === null ? null : trim((string) $etag, '"');
    }

    private function bodyToString(mixed $body): string
    {
        if (is_string($body)) {
            return $body;
        }

        if (is_resource($body)) {
            $contents = stream_get_contents($body);
            return $contents === false ? '' : $contents;
        }

        if (is_object($body) && method_exists($body, 'getContents')) {
            return (string) $body->getContents();
        }

        return (string) $body;
    }

    private function mapException(AwsException $e, ?ObjectKey $key = null): never
    {
        $code = $e->getAwsErrorCode() ?? '';
        $message = $e->getAwsErrorMessage() ?? $e->getMessage();

        if (in_array($code, ['NoSuchKey', 'NotFound', '404'], true)) {
            throw ObjectNotFoundException::forKey($key?->value() ?? 'unknown');
        }

        if (in_array($code, ['NoSuchBucket'], true)) {
            throw BucketNotFoundException::forBucket($this->bucket);
        }

        if (in_array($code, ['AccessDenied', 'InvalidAccessKeyId', 'SignatureDoesNotMatch'], true)) {
            throw new ObjectStoreAccessDeniedException($message, previous: $e);
        }

        if (in_array($code, ['SlowDown', 'Throttling', 'ThrottlingException', 'TooManyRequestsException'], true)) {
            throw new ObjectStoreRateLimitException($message, previous: $e);
        }

        throw new ObjectStoreException(sprintf('%s object store error: %s', $this->provider, $message), previous: $e);
    }
}
