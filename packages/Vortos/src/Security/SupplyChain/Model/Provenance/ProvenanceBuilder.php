<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Provenance;

final readonly class ProvenanceBuilder
{
    public function __construct(
        public string $builderId,
        public ?string $version = null,
    ) {
        if ($builderId === '') {
            throw new \InvalidArgumentException('Provenance builder ID must not be empty.');
        }
    }

    /** @return array{builder_id: string, version: ?string} */
    public function toArray(): array
    {
        return [
            'builder_id' => $this->builderId,
            'version' => $this->version,
        ];
    }

    /** @param array{builder_id: string, version?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            builderId: $data['builder_id'],
            version: $data['version'] ?? null,
        );
    }
}
