<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PutObjectOptions
{
    /** @param array<string, string> $metadata */
    public function __construct(
        private readonly ?ContentType $contentType = null,
        private readonly array $metadata = [],
        private readonly ?string $cacheControl = null,
        private readonly ?string $contentDisposition = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function contentType(): ?ContentType
    {
        return $this->contentType;
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function cacheControl(): ?string
    {
        return $this->cacheControl;
    }

    public function contentDisposition(): ?string
    {
        return $this->contentDisposition;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }
}
