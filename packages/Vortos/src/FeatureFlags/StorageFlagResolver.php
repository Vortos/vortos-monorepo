<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Storage-backed flag resolver with a per-request memo, so a prerequisite chain
 * referencing the same flag many times costs at most one lookup per flag per request.
 * Separate from {@see FlagRegistry} to avoid a constructor cycle (registry → evaluator
 * → resolver).
 */
final class StorageFlagResolver implements FlagResolverInterface, ResetInterface
{
    /** @var array<string,FeatureFlag|null> */
    private array $memo = [];

    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {}

    public function resolve(string $name): ?FeatureFlag
    {
        if (!array_key_exists($name, $this->memo)) {
            $this->memo[$name] = $this->storage->findByName($name);
        }

        return $this->memo[$name];
    }

    public function reset(): void
    {
        $this->memo = [];
    }
}
