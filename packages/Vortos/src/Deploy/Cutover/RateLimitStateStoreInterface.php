<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

interface RateLimitStateStoreInterface
{
    public function loadLastReloadTimestamp(string $env): ?float;

    public function saveLastReloadTimestamp(string $env, float $timestamp): void;
}
