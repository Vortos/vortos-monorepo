<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

use Vortos\ObjectStore\Exception\InvalidUploadConstraintException;

final class UploadConstraints
{
    public function __construct(
        private readonly ?ContentType $contentType = null,
        private readonly int $minSizeBytes = 0,
        private readonly ?int $maxSizeBytes = null,
        private readonly array $requiredHeaders = [],
    ) {
        if ($minSizeBytes < 0) {
            throw new InvalidUploadConstraintException('Minimum upload size cannot be negative.');
        }

        if ($maxSizeBytes !== null && $maxSizeBytes < $minSizeBytes) {
            throw new InvalidUploadConstraintException('Maximum upload size must be greater than or equal to minimum size.');
        }
    }

    public static function forDirectUpload(
        ?string $contentType = null,
        ?int $maxSizeBytes = null,
        int $minSizeBytes = 0,
    ): self {
        return new self(ContentType::from($contentType), $minSizeBytes, $maxSizeBytes);
    }

    public function contentType(): ?ContentType
    {
        return $this->contentType;
    }

    public function minSizeBytes(): int
    {
        return $this->minSizeBytes;
    }

    public function maxSizeBytes(): ?int
    {
        return $this->maxSizeBytes;
    }

    /** @return array<string, string> */
    public function requiredHeaders(): array
    {
        $headers = $this->requiredHeaders;

        if ($this->contentType !== null) {
            $headers['Content-Type'] = $this->contentType->value();
        }

        return $headers;
    }

    /** @return array<int, mixed> */
    public function postPolicyContentLengthRange(): array
    {
        return ['content-length-range', $this->minSizeBytes, $this->maxSizeBytes ?? PHP_INT_MAX];
    }
}
