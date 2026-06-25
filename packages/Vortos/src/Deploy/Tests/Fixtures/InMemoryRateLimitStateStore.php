<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Deploy\Cutover\RateLimitStateStoreInterface;

final class InMemoryRateLimitStateStore implements RateLimitStateStoreInterface
{
    /** @var array<string, float> */
    private array $timestamps = [];

    public function loadLastReloadTimestamp(string $env): ?float
    {
        return $this->timestamps[$env] ?? null;
    }

    public function saveLastReloadTimestamp(string $env, float $timestamp): void
    {
        $this->timestamps[$env] = $timestamp;
    }
}
