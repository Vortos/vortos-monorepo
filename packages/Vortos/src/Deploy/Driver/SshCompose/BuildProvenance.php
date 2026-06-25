<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

final readonly class BuildProvenance
{
    public function __construct(
        public string $builderId,
        public ?string $baseImageDigest = null,
        public ?string $sourceDigest = null,
    ) {}

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'builder_id' => $this->builderId,
            'base_image_digest' => $this->baseImageDigest,
            'source_digest' => $this->sourceDigest,
        ];
    }
}
