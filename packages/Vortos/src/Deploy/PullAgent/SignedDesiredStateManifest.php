<?php

declare(strict_types=1);

namespace Vortos\Deploy\PullAgent;

final readonly class SignedDesiredStateManifest
{
    public function __construct(
        public DesiredStateManifest $manifest,
        public string $signature,
        public string $signerKeyId,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'manifest' => $this->manifest->toArray(),
            'signature' => $this->signature,
            'signer_key_id' => $this->signerKeyId,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            manifest: DesiredStateManifest::fromArray($data['manifest']),
            signature: (string) $data['signature'],
            signerKeyId: (string) $data['signer_key_id'],
        );
    }
}
