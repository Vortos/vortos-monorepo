<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Storage;

use Vortos\FeatureFlags\ChangeRequest\EnvironmentProtection;

interface EnvironmentProtectionStorageInterface
{
    public function findForEnvironment(string $environment, string $projectId = 'default'): ?EnvironmentProtection;

    public function save(EnvironmentProtection $protection): void;
}
