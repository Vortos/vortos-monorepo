<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Failover;

use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\Exception\ObjectNotFoundException;
use Vortos\ObjectStore\Exception\ObjectStoreException;
use Vortos\ObjectStore\Exception\ObjectStoreRateLimitException;
use Vortos\ObjectStore\Exception\ObjectTooLargeException;
use Vortos\ObjectStore\Exception\PresignedUrlPolicyException;
use Vortos\ObjectStore\Exception\PromotionRejectedException;
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

/**
 * Wraps an ObjectStoreInterface driver with a circuit breaker.
 *
 * Infrastructure failures (network errors, provider 5xx, unexpected exceptions)
 * trip the circuit after failureThreshold consecutive occurrences. Application-
 * level exceptions (object not found, size limit, policy violation, rate limit)
 * pass through without affecting the circuit state.
 */
final class CircuitBreakerObjectStore implements ObjectStoreInterface
{
    public function __construct(
        private readonly ObjectStoreInterface $inner,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function put(ObjectKey|string $key, mixed $body, ?PutObjectOptions $options = null): StoredObject
    {
        return $this->protect(fn(): StoredObject => $this->inner->put($key, $body, $options));
    }

    public function get(ObjectKey|string $key, ?GetObjectOptions $options = null): ObjectBody
    {
        return $this->protect(fn(): ObjectBody => $this->inner->get($key, $options));
    }

    public function stream(ObjectKey|string $key, ?GetObjectOptions $options = null): mixed
    {
        return $this->protect(fn(): mixed => $this->inner->stream($key, $options));
    }

    public function head(ObjectKey|string $key): ObjectMetadata
    {
        return $this->protect(fn(): ObjectMetadata => $this->inner->head($key));
    }

    public function exists(ObjectKey|string $key): bool
    {
        return $this->protect(fn(): bool => $this->inner->exists($key));
    }

    public function delete(ObjectKey|string $key): DeleteResult
    {
        return $this->protect(fn(): DeleteResult => $this->inner->delete($key));
    }

    public function deleteMany(array $keys): BulkDeleteResult
    {
        return $this->protect(fn(): BulkDeleteResult => $this->inner->deleteMany($keys));
    }

    public function copy(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->protect(fn(): StoredObject => $this->inner->copy($source, $target, $options));
    }

    public function move(ObjectKey|string $source, ObjectKey|string $target, ?CopyObjectOptions $options = null): StoredObject
    {
        return $this->protect(fn(): StoredObject => $this->inner->move($source, $target, $options));
    }

    public function list(?ListObjectsOptions $options = null): ObjectListing
    {
        return $this->protect(fn(): ObjectListing => $this->inner->list($options));
    }

    public function temporaryDownloadUrl(ObjectKey|string $key, \DateTimeImmutable $expiresAt, ?GetObjectOptions $options = null): PresignedUrl
    {
        return $this->protect(fn(): PresignedUrl => $this->inner->temporaryDownloadUrl($key, $expiresAt, $options));
    }

    public function temporaryUploadUrl(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedUploadUrl
    {
        return $this->protect(fn(): PresignedUploadUrl => $this->inner->temporaryUploadUrl($key, $options));
    }

    public function temporaryPostUpload(ObjectKey|string $key, TemporaryUploadUrlOptions $options): PresignedPostPolicy
    {
        return $this->protect(fn(): PresignedPostPolicy => $this->inner->temporaryPostUpload($key, $options));
    }

    public function circuitState(): CircuitBreakerState
    {
        return $this->breaker->state();
    }

    private function protect(callable $operation): mixed
    {
        if (!$this->breaker->isAvailable()) {
            throw new ObjectStoreException('Object store circuit breaker is open.');
        }

        try {
            $result = $operation();
            $this->breaker->recordSuccess();
            return $result;
        } catch (\Throwable $e) {
            if (!$this->isApplicationException($e)) {
                $this->breaker->recordFailure();
            }
            throw $e;
        }
    }

    private function isApplicationException(\Throwable $e): bool
    {
        return $e instanceof ObjectNotFoundException
            || $e instanceof ObjectTooLargeException
            || $e instanceof PresignedUrlPolicyException
            || $e instanceof PromotionRejectedException
            || $e instanceof ObjectStoreRateLimitException
            || $e instanceof \InvalidArgumentException;
    }
}
