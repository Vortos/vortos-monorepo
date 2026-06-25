<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Provenance;

use Vortos\Security\SupplyChain\Model\ArtifactDigest;

final readonly class ProvenanceSubject
{
    public function __construct(
        public string $name,
        public ArtifactDigest $digest,
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('Provenance subject name must not be empty.');
        }
    }

    /** @return array{name: string, digest: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'digest' => $this->digest->toString(),
        ];
    }

    /** @param array{name: string, digest: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            digest: new ArtifactDigest($data['digest']),
        );
    }
}
