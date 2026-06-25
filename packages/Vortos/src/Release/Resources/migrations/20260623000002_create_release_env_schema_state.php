<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Release';
    }

    public function id(): string
    {
        return 'release.create_env_schema_state';
    }

    public function description(): string
    {
        return 'Create per-environment schema state snapshot table (Block 3)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('release_env_schema_state'));
        $table->addColumn('environment',   'string',             ['length' => 64, 'notnull' => true]);
        $table->addColumn('schema_hash',   'string',             ['length' => 71, 'notnull' => true]);
        $table->addColumn('migration_ids', 'text',               ['notnull' => true]);
        $table->addColumn('updated_at',    'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['environment']);
    }
};
