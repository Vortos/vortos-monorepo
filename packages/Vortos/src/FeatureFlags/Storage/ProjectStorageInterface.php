<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Vortos\FeatureFlags\Project;

interface ProjectStorageInterface
{
    /** @return Project[] */
    public function findAll(): array;

    public function findBySlug(string $slug): ?Project;

    public function findById(string $id): ?Project;

    /** @internal Route through FlagWriteService or dedicated project management service. */
    public function save(Project $project): void;

    /** @internal */
    public function delete(string $slug): void;
}
