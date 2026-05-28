<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

final class CopyObjectOptions
{
    /** @param array<string, string> $metadata */
    public function __construct(
        private readonly array $metadata = [],
        private readonly bool $replaceMetadata = false,
    ) {}

    /** @return array<string, string> */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function replaceMetadata(): bool
    {
        return $this->replaceMetadata;
    }
}
