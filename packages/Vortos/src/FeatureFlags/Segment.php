<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * A named, reusable audience definition — a set of targeting rules referenced by many
 * flags via a {@see FlagRule::TYPE_SEGMENT} rule. Editing a segment propagates to every
 * flag that references it. Rules reuse the same composition engine as flags (AND/OR
 * groups, all operators).
 */
final class Segment
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        /** @var FlagRule[] */
        public readonly array $rules,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly string $projectId = ProjectContext::DEFAULT_PROJECT,
    ) {}

    /** @param FlagRule[] $rules */
    public function withRules(array $rules): self
    {
        return new self(
            id:          $this->id,
            name:        $this->name,
            description: $this->description,
            rules:       $rules,
            createdAt:   $this->createdAt,
            updatedAt:   new \DateTimeImmutable(),
            projectId:   $this->projectId,
        );
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'rules'       => array_map(fn(FlagRule $r) => $r->toArray(), $this->rules),
            'project_id'  => $this->projectId,
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
            rules:       array_map(fn(array $r) => FlagRule::fromArray($r), $data['rules'] ?? []),
            createdAt:   new \DateTimeImmutable($data['created_at']),
            updatedAt:   new \DateTimeImmutable($data['updated_at']),
            projectId:   $data['project_id'] ?? ProjectContext::DEFAULT_PROJECT,
        );
    }
}
