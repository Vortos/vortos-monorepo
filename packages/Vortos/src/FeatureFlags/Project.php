<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * A Project is the top-level isolation unit in the Feature Flags platform (Block 11).
 *
 * Hierarchy: Project → Environment → Flag. Flags and segments are scoped to a project;
 * RBAC (Block 13) and SDK keys (Block 13) scope to a project + environment pair.
 *
 * The slug is a short URL-safe identifier used in API paths and CLI commands.
 * The 'default' project is pre-seeded for back-compat with Phase A/B flags.
 */
final class Project
{
    public const DEFAULT_ID   = 'default';
    public const DEFAULT_SLUG = 'default';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $description,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'created_at'  => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at'  => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:          (string) $data['id'],
            name:        (string) $data['name'],
            slug:        (string) $data['slug'],
            description: (string) ($data['description'] ?? ''),
            createdAt:   new \DateTimeImmutable($data['created_at']),
            updatedAt:   new \DateTimeImmutable($data['updated_at']),
        );
    }

    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        return trim($slug, '-');
    }
}
