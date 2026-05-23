<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Migration\Schema\ModuleMigrationDescriptor;

interface ModuleMigrationRegistryInterface
{
    /** @return array<string, ModuleMigrationDescriptor> */
    public function descriptorsByClass(): array;

    public function descriptorForClass(string $class): ?ModuleMigrationDescriptor;
}
