<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

final class ModuleMigrationDescriptor
{
    public function __construct(
        private readonly string $source,
        private readonly string $class,
        private readonly string $module,
        private readonly string $filename,
        private readonly MigrationOwnership $ownership,
        private readonly ?ModuleSchemaProviderInterface $provider = null,
        private readonly ?string $checksum = null,
    ) {
    }

    public function source(): string
    {
        return $this->source;
    }

    public function class(): string
    {
        return $this->class;
    }

    public function module(): string
    {
        return $this->module;
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function ownership(): MigrationOwnership
    {
        return $this->ownership;
    }

    public function provider(): ?ModuleSchemaProviderInterface
    {
        return $this->provider;
    }

    public function checksum(): ?string
    {
        return $this->checksum;
    }
}
