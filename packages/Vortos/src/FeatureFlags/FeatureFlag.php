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

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'enabled'     => $this->enabled,
            'rules'       => array_map(fn(FlagRule $r) => $r->toArray(), $this->rules),
            'variants'    => $this->variants,
            'created_at'  => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'  => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:          $data['id'],
            name:        $data['name'],
            description: $data['description'],
            enabled:     (bool) $data['enabled'],
            rules:       array_map(fn(array $r) => FlagRule::fromArray($r), $data['rules'] ?? []),
            variants:    $data['variants'] ?? null,
            createdAt:   new \DateTimeImmutable($data['created_at']),
            updatedAt:   new \DateTimeImmutable($data['updated_at']),
        );
    }
}
