<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Benchmark;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * In-memory storage for the benchmark harness.
 * Eliminates DB I/O so the bench measures the engine, not storage latency.
 */
final class InMemoryFlagStorage implements FlagStorageInterface
{
    /** @var array<string, FeatureFlag> */
    private array $flags = [];

    public function add(FeatureFlag $flag): void
    {
        $this->flags[$flag->name] = $flag;
    }

    /** @return FeatureFlag[] */
    public function findAll(): array
    {
        return array_values($this->flags);
    }

    public function findByName(string $name): ?FeatureFlag
    {
        return $this->flags[$name] ?? null;
    }

    public function save(FeatureFlag $flag): void
    {
        $this->flags[$flag->name] = $flag;
    }

    public function delete(string $name): void
    {
        unset($this->flags[$name]);
    }
}
