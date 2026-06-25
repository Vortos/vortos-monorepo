<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Provenance;

use Vortos\Security\SupplyChain\Model\ArtifactDigest;

final readonly class ProvenanceMaterial
{
    public function __construct(
        public string $uri,
        public ArtifactDigest $digest,
    ) {
        if ($uri === '') {
            throw new \InvalidArgumentException('Provenance material URI must not be empty.');
        }
    }

    /** @return array{uri: string, digest: string} */
    public function toArray(): array
    {
        return [
            'uri' => $this->uri,
            'digest' => $this->digest->toString(),
        ];
    }

    /** @param array{uri: string, digest: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            uri: $data['uri'],
            digest: new ArtifactDigest($data['digest']),
        );
    }
}
