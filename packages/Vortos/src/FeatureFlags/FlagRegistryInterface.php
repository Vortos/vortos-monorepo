<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

interface FlagRegistryInterface
{
    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool;
    public function variant(string $name, FlagContext $context = new FlagContext()): string;
    public function allForContext(FlagContext $context = new FlagContext()): array;
}
