<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

use Doctrine\DBAL\Schema\Schema;

interface ModuleSchemaProviderInterface
{
    public function module(): string;

    public function id(): string;

    public function description(): string;

    public function define(Schema $schema): void;

    public function ownership(): MigrationOwnership;
}
