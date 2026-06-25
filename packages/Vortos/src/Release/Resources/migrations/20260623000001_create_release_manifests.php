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
        return 'release.create_build_manifests';
    }

    public function description(): string
    {
        return 'Create release build manifests table (append-only, write-once) + immutability trigger (Block 3)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('release_build_manifests'));
        $table->addColumn('build_id',     'string',             ['length' => 36, 'notnull' => true]);
        $table->addColumn('git_sha',      'string',             ['length' => 40, 'notnull' => true]);
        $table->addColumn('image_digest', 'string',             ['length' => 71, 'notnull' => true]);
        $table->addColumn('target_arch',  'string',             ['length' => 20, 'notnull' => true]);
        $table->addColumn('environment',  'string',             ['length' => 64, 'notnull' => true]);
        $table->addColumn('schema_hash',  'string',             ['length' => 71, 'notnull' => true]);
        $table->addColumn('migration_ids','text',               ['notnull' => true]);
        $table->addColumn('provenance',   'text',               ['notnull' => false]);
        $table->addColumn('created_at',   'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['build_id']);
        $table->addIndex(['environment', 'created_at'], 'idx_release_env_created');
        $table->addIndex(['schema_hash'], 'idx_release_schema_hash');
    }
};
