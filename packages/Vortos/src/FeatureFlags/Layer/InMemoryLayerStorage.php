<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

/** In-memory layer storage for tests and simple deployments. */
final class InMemoryLayerStorage implements LayerStorageInterface
{
    /** @var array<string, Layer> keyed by id */
    private array $layers = [];

    public function findById(string $id): ?Layer
    {
        return $this->layers[$id] ?? null;
    }

    public function findByName(string $name): ?Layer
    {
        foreach ($this->layers as $layer) {
            if ($layer->name === $name) {
                return $layer;
            }
        }

        return null;
    }

    public function findByFlagName(string $flagName): ?Layer
    {
        foreach ($this->layers as $layer) {
            if ($layer->findMember($flagName) !== null) {
                return $layer;
            }
        }

        return null;
    }

    /** @return Layer[] */
    public function findAll(): array
    {
        return array_values($this->layers);
    }

    public function save(Layer $layer): void
    {
        $this->layers[$layer->id] = $layer;
    }

    public function delete(string $id): void
    {
        unset($this->layers[$id]);
    }
}
