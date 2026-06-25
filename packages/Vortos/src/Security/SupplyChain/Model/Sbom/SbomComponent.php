<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Sbom;

final readonly class SbomComponent
{
    /** @param list<string> $licenses */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $purl = null,
        public array $licenses = [],
    ) {
        if ($name === '') {
            throw new \InvalidArgumentException('SBOM component name must not be empty.');
        }
        if ($version === '') {
            throw new \InvalidArgumentException('SBOM component version must not be empty.');
        }
    }

    /** @return array{name: string, version: string, purl: ?string, licenses: list<string>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'purl' => $this->purl,
            'licenses' => $this->licenses,
        ];
    }

    /** @param array{name: string, version: string, purl?: ?string, licenses?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            version: $data['version'],
            purl: $data['purl'] ?? null,
            licenses: $data['licenses'] ?? [],
        );
    }
}
