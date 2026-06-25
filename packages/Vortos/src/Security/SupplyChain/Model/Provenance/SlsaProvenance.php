<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Provenance;

final readonly class SlsaProvenance
{
    /**
     * @param list<ProvenanceSubject>  $subjects
     * @param list<ProvenanceMaterial> $materials
     */
    public function __construct(
        public string $predicateType,
        public ProvenanceBuilder $builder,
        public string $buildType,
        public array $subjects,
        public array $materials = [],
    ) {
        if ($predicateType === '') {
            throw new \InvalidArgumentException('SLSA provenance predicate type must not be empty.');
        }
        if ($buildType === '') {
            throw new \InvalidArgumentException('SLSA provenance build type must not be empty.');
        }
        if ($subjects === []) {
            throw new \InvalidArgumentException('SLSA provenance must have at least one subject.');
        }
    }

    /** @return array{predicate_type: string, builder: array, build_type: string, subjects: list<array>, materials: list<array>} */
    public function toArray(): array
    {
        return [
            'predicate_type' => $this->predicateType,
            'builder' => $this->builder->toArray(),
            'build_type' => $this->buildType,
            'subjects' => array_map(
                static fn (ProvenanceSubject $s): array => $s->toArray(),
                $this->subjects,
            ),
            'materials' => array_map(
                static fn (ProvenanceMaterial $m): array => $m->toArray(),
                $this->materials,
            ),
        ];
    }

    /** @param array{predicate_type: string, builder: array, build_type: string, subjects: list<array>, materials?: list<array>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            predicateType: $data['predicate_type'],
            builder: ProvenanceBuilder::fromArray($data['builder']),
            buildType: $data['build_type'],
            subjects: array_map(
                static fn (array $s): ProvenanceSubject => ProvenanceSubject::fromArray($s),
                $data['subjects'],
            ),
            materials: array_map(
                static fn (array $m): ProvenanceMaterial => ProvenanceMaterial::fromArray($m),
                $data['materials'] ?? [],
            ),
        );
    }
}
