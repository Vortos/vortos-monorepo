<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Resolution;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * The base link of the resolution chain: the global flag state straight from storage.
 * Behaviourally identical to reading storage directly — this exists so higher links
 * (tenant overrides) can decorate a uniform interface.
 */
final class GlobalFlagResolver implements EffectiveFlagResolverInterface
{
    public function __construct(private readonly FlagStorageInterface $storage) {}

    public function resolve(string $name, FlagContext $context): ?FeatureFlag
    {
        return $this->storage->findByName($name);
    }

    public function resolveAll(FlagContext $context): array
    {
        return $this->storage->findAll();
    }
}
