<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Middleware;

use Psr\Clock\ClockInterface;
use Vortos\ObjectStore\Attribute\AsObjectStoreMiddleware;
use Vortos\ObjectStore\Contract\ObjectStoreMiddlewareInterface;
use Vortos\ObjectStore\Contract\ObjectStoreOperation;
use Vortos\ObjectStore\Contract\ObjectStoreOperationName;
use Vortos\ObjectStore\Exception\PresignedUrlPolicyException;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

#[AsObjectStoreMiddleware(priority: 875)]
final class PresignedUrlPolicyMiddleware implements ObjectStoreMiddlewareInterface
{
    public function __construct(
        private readonly int $maxPresignTtlSeconds,
        private readonly int $maxUploadSizeBytes,
        private readonly ClockInterface $clock,
    ) {
        if ($maxPresignTtlSeconds <= 0) {
            throw new \InvalidArgumentException('Maximum presign TTL must be greater than zero.');
        }

        if ($maxUploadSizeBytes <= 0) {
            throw new \InvalidArgumentException('Maximum upload size must be greater than zero.');
        }
    }

    public function process(ObjectStoreOperation $operation, callable $next): mixed
    {
        match ($operation->typedName()) {
            ObjectStoreOperationName::TemporaryDownloadUrl => $this->assertExpiresAt($operation->context()['expires_at'] ?? null),
            ObjectStoreOperationName::TemporaryUploadUrl,
            ObjectStoreOperationName::TemporaryPostUpload => $this->assertUploadOptions($operation->context()['options'] ?? null),
            default => null,
        };

        return $next($operation);
    }

    private function assertUploadOptions(mixed $options): void
    {
        if (!$options instanceof TemporaryUploadUrlOptions) {
            return;
        }

        $this->assertExpiresAt($options->expiresAt());
        $maxSizeBytes = $options->constraints()->maxSizeBytes();
        if ($maxSizeBytes !== null && $maxSizeBytes > $this->maxUploadSizeBytes) {
            throw PresignedUrlPolicyException::uploadTooLarge($maxSizeBytes, $this->maxUploadSizeBytes);
        }
    }

    private function assertExpiresAt(mixed $expiresAt): void
    {
        if (!$expiresAt instanceof \DateTimeImmutable) {
            return;
        }

        $ttlSeconds = $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttlSeconds > $this->maxPresignTtlSeconds) {
            throw PresignedUrlPolicyException::ttlTooLong($ttlSeconds, $this->maxPresignTtlSeconds);
        }
    }
}
