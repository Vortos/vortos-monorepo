<?php

declare(strict_types=1);

namespace Vortos\Release\Manifest;

final readonly class Provenance
{
    public function __construct(
        public string $builderId,
        public ?string $baseImageDigest = null,
        public ?string $signature = null,
        public ?string $attestation = null,
    ) {}

    /** @return array{builder_id: string, base_image_digest: ?string, signature: ?string, attestation: ?string} */
    public function toArray(): array
    {
        return [
            'builder_id' => $this->builderId,
            'base_image_digest' => $this->baseImageDigest,
            'signature' => $this->signature,
            'attestation' => $this->attestation,
        ];
    }

    /** @param array{builder_id: string, base_image_digest?: ?string, signature?: ?string, attestation?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            builderId: $data['builder_id'],
            baseImageDigest: $data['base_image_digest'] ?? null,
            signature: $data['signature'] ?? null,
            attestation: $data['attestation'] ?? null,
        );
    }
}
