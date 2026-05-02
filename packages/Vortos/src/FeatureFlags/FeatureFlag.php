<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

final class FeatureFlag
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly bool $enabled,
        /** @var FlagRule[] */
        public readonly array $rules,
        /** @var array<string,int>|null variant name → percentage */
        public readonly ?array $variants,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function withEnabled(bool $enabled): self
    {
        return new self(
            $this->id, $this->name, $this->description,
            $enabled, $this->rules, $this->variants,
            $this->createdAt, new \DateTimeImmutable(),
        );
    }

    public function withRules(array $rules): self
    {
        return new self(
            $this->id, $this->name, $this->description,
            $this->enabled, $rules, $this->variants,
            $this->createdAt, new \DateTimeImmutable(),
        );
    }

    public function isVariant(): bool
    {
        return $this->variants !== null;
    }
}
