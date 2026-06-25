<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model;

final readonly class ArtifactRef
{
    public function __construct(
        public string $repository,
        public ArtifactDigest $digest,
        public ?string $tag = null,
    ) {
        if ($repository === '') {
            throw new \InvalidArgumentException('Artifact repository must not be empty.');
        }
    }

    public function toString(): string
    {
        $ref = $this->repository;

        if ($this->tag !== null) {
            $ref .= ':' . $this->tag;
        }

        $ref .= '@' . $this->digest->toString();

        return $ref;
    }

    /** @return array{repository: string, digest: string, tag: ?string} */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository,
            'digest' => $this->digest->toString(),
            'tag' => $this->tag,
        ];
    }

    /** @param array{repository: string, digest: string, tag?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            repository: $data['repository'],
            digest: new ArtifactDigest($data['digest']),
            tag: $data['tag'] ?? null,
        );
    }
}
