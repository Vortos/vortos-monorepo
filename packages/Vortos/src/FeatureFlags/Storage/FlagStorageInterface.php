<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

use Vortos\FeatureFlags\FeatureFlag;

interface FlagStorageInterface
{
    /** @return FeatureFlag[] */
    public function findAll(): array;

    public function findByName(string $name): ?FeatureFlag;

    public function save(FeatureFlag $flag): void;

    public function delete(string $name): void;
}
