<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class TemporaryUploadUrlOptions
{
    /** @param array<string, string> $metadata */
    public function __construct(
        private readonly \DateTimeImmutable $expiresAt,
        private readonly UploadConstraints $constraints,
        private readonly UploadMethod $method = UploadMethod::SignedPut,
        private readonly array $metadata = [],
    ) {}

    public static function forDirectUpload(
        int $ttlSeconds = 900,
        ?string $contentType = null,
        ?int $maxSizeBytes = null,
        UploadMethod $method = UploadMethod::SignedPut,
    ): self {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('Upload URL TTL must be greater than zero.');
        }

        return new self(
            expiresAt: (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttlSeconds)),
            constraints: UploadConstraints::forDirectUpload($contentType, $maxSizeBytes),
            method: $method,
        );
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function constraints(): UploadConstraints
    {
        return $this->constraints;
    }

    public function method(): UploadMethod
    {
        return $this->method;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
