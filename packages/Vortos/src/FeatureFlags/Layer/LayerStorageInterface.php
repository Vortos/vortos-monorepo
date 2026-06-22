<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

interface LayerStorageInterface
{
    public function findById(string $id): ?Layer;

    public function findByName(string $name): ?Layer;

    /** Find the layer a given flag name belongs to, or null. */
    public function findByFlagName(string $flagName): ?Layer;

    /** @return Layer[] */
    public function findAll(): array;

    public function save(Layer $layer): void;

    public function delete(string $id): void;
}
