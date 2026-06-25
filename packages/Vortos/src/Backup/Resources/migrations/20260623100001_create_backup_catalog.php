<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Backup';
    }

    public function id(): string
    {
        return 'backup.create_catalog';
    }

    public function description(): string
    {
        return 'Create backup catalog table (append-only; UPDATE blocked by trigger, DELETE allowed for retention) (Block 19)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('backup_catalog'));
        $table->addColumn('id',                 'string',             ['length' => 96,  'notnull' => true]);
        $table->addColumn('engine',             'string',             ['length' => 32,  'notnull' => true]);
        $table->addColumn('kind',               'string',             ['length' => 32,  'notnull' => true]);
        $table->addColumn('environment',        'string',             ['length' => 64,  'notnull' => true]);
        $table->addColumn('created_at',         'datetime_immutable', ['notnull' => true]);
        $table->addColumn('size_bytes',         'bigint',             ['notnull' => true]);
        $table->addColumn('checksum_algo',      'string',             ['length' => 32,  'notnull' => true]);
        $table->addColumn('checksum_hex',       'string',             ['length' => 128, 'notnull' => true]);
        $table->addColumn('store_key',          'string',             ['length' => 512, 'notnull' => true]);
        $table->addColumn('codec',              'string',             ['length' => 16,  'notnull' => true]);
        $table->addColumn('source_ref',         'text',               ['notnull' => true]);
        $table->addColumn('parent_id',          'string',             ['length' => 96,  'notnull' => false]);
        $table->addColumn('schema_fingerprint', 'string',             ['length' => 128, 'notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['store_key'], 'uniq_backup_store_key');
        $table->addIndex(['engine', 'environment', 'created_at'], 'idx_backup_engine_env_created');
        $table->addIndex(['kind'], 'idx_backup_kind');
    }
};
