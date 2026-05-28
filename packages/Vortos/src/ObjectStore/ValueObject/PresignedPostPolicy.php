<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class PresignedPostPolicy
{
    /** @param array<string, string> $fields */
    public function __construct(
        private readonly ObjectKey $key,
        private readonly string $url,
        private readonly \DateTimeImmutable $expiresAt,
        private readonly UploadConstraints $constraints,
        private readonly array $fields,
    ) {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Presigned POST URL must be a valid URL.');
        }
    }

    public function key(): ObjectKey
    {
        return $this->key;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function constraints(): UploadConstraints
    {
        return $this->constraints;
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
