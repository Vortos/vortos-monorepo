<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry;

final readonly class ImageReference
{
    public function __construct(
        public string $repository,
        public ?string $tag = null,
        public ?string $digest = null,
    ) {
        if ($repository === '') {
            throw new \InvalidArgumentException('Repository must not be empty.');
        }

        if ($digest !== null && !preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new \InvalidArgumentException(sprintf(
                'Digest must match sha256:<64 hex>, got "%s".',
                $digest,
            ));
        }
    }

    public function isDigestPinned(): bool
    {
        return $this->digest !== null;
    }

    public function withDigest(string $digest): self
    {
        return new self($this->repository, $this->tag, $digest);
    }

    public function withTag(string $tag): self
    {
        return new self($this->repository, $tag, $this->digest);
    }

    public function toString(): string
    {
        $ref = $this->repository;

        if ($this->tag !== null) {
            $ref .= ':' . $this->tag;
        }

        if ($this->digest !== null) {
            $ref .= '@' . $this->digest;
        }

        return $ref;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository,
            'tag' => $this->tag,
            'digest' => $this->digest,
        ];
    }

    /** @param array<string, string|null> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            repository: $data['repository'] ?? '',
            tag: $data['tag'] ?? null,
            digest: $data['digest'] ?? null,
        );
    }
}
