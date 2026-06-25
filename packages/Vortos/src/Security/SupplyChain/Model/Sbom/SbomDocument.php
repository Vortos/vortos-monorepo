<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Sbom;

final readonly class SbomDocument
{
    /** @param list<SbomComponent> $components */
    public function __construct(
        public SbomFormat $format,
        public string $specVersion,
        public array $components,
    ) {
        if ($specVersion === '') {
            throw new \InvalidArgumentException('SBOM spec version must not be empty.');
        }
    }

    public function contentHash(): string
    {
        $normalized = $this->toArray();
        ksort($normalized);
        $normalized['components'] = array_map(
            static fn (array $c): array => (function (array $c): array { ksort($c); return $c; })($c),
            $normalized['components'],
        );
        usort($normalized['components'], static fn (array $a, array $b): int => ($a['name'] . $a['version']) <=> ($b['name'] . $b['version']));

        return 'sha256:' . hash('sha256', json_encode($normalized, \JSON_THROW_ON_ERROR));
    }

    public function componentCount(): int
    {
        return count($this->components);
    }

    /** @return array{format: string, spec_version: string, components: list<array{name: string, version: string, purl: ?string, licenses: list<string>}>} */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'spec_version' => $this->specVersion,
            'components' => array_map(
                static fn (SbomComponent $c): array => $c->toArray(),
                $this->components,
            ),
        ];
    }

    /** @param array{format: string, spec_version: string, components?: list<array{name: string, version: string, purl?: ?string, licenses?: list<string>}>} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            format: SbomFormat::from($data['format']),
            specVersion: $data['spec_version'],
            components: array_map(
                static fn (array $c): SbomComponent => SbomComponent::fromArray($c),
                $data['components'] ?? [],
            ),
        );
    }
}
