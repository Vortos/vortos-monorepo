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
        return 'backup.add_encryption_and_drill';
    }

    public function description(): string
    {
        return 'Add encryption columns to backup catalog and create drill report table (Block 20)';
    }

    public function define(Schema $schema): void
    {
        // Guard the ALTER on the pre-existing catalog table: at publish time define() runs
        // against a fresh Schema (the base table is not present), so an unguarded getTable()
        // throws and aborts the whole publish run. hasTable() is the framework-wide pattern for
        // alter-style providers (see FeatureFlags add_projects/add_lifecycle/…).
        if ($schema->hasTable($this->t('backup_catalog'))) {
            $catalog = $schema->getTable($this->t('backup_catalog'));
            if (!$catalog->hasColumn('encryption_provider')) {
                $catalog->addColumn('encryption_provider',  'string',  ['length' => 32,  'notnull' => false]);
                $catalog->addColumn('encryption_recipient', 'string',  ['length' => 64,  'notnull' => false]);
                $catalog->addColumn('encryption_aead_id',   'smallint', ['notnull' => false]);
                $catalog->addColumn('secondary_store_key',  'string',  ['length' => 512, 'notnull' => false]);
            }
        }

        $drill = $schema->createTable($this->t('backup_drill_report'));
        $drill->addColumn('id',          'string',             ['length' => 96,  'notnull' => true]);
        $drill->addColumn('engine',      'string',             ['length' => 32,  'notnull' => true]);
        $drill->addColumn('environment', 'string',             ['length' => 64,  'notnull' => true]);
        $drill->addColumn('artifact_id', 'string',             ['length' => 96,  'notnull' => true]);
        $drill->addColumn('started_at',  'datetime_immutable', ['notnull' => true]);
        $drill->addColumn('rto_ms',      'integer',            ['notnull' => true]);
        $drill->addColumn('outcome',     'string',             ['length' => 16,  'notnull' => true]);
        $drill->addColumn('invariants',  'text',               ['notnull' => true]);
        $drill->addColumn('error',       'text',               ['notnull' => false]);

        $drill->setPrimaryKey(['id']);
        $drill->addIndex(['engine', 'environment', 'started_at'], 'idx_drill_engine_env_started');
        $drill->addIndex(['outcome'], 'idx_drill_outcome');
    }
};
