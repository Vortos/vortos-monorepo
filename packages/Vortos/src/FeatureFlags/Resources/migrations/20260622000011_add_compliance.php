<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'FeatureFlags';
    }

    public function id(): string
    {
        return 'feature_flags.add_compliance';
    }

    public function description(): string
    {
        return 'Add compliance export manifest log and residency policy tables (Block 29)';
    }

    public function define(Schema $schema): void
    {
        if (!$schema->hasTable($this->t('ff_export_manifests'))) {
            $table = $schema->createTable($this->t('ff_export_manifests'));
            $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
            $table->addColumn('schema_version', 'integer', ['notnull' => true, 'default' => 1]);
            $table->addColumn('format', 'string', ['length' => 8, 'notnull' => true]);
            $table->addColumn('row_count', 'integer', ['notnull' => true]);
            $table->addColumn('range_from', 'datetime', ['notnull' => false]);
            $table->addColumn('range_to', 'datetime', ['notnull' => false]);
            $table->addColumn('generated_at', 'datetime', ['notnull' => true]);
            $table->addColumn('generator_identity', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('content_hash', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('signature', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('filter_json', 'json', ['notnull' => false]);
            $table->addColumn('created_by', 'string', ['length' => 255, 'notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['generated_at'], 'idx_ff_export_generated_at');
        }

        if (!$schema->hasTable($this->t('ff_residency_policies'))) {
            $table = $schema->createTable($this->t('ff_residency_policies'));
            $table->addColumn('tenant_id', 'string', ['length' => 128, 'notnull' => true]);
            $table->addColumn('region', 'string', ['length' => 8, 'notnull' => true]);
            $table->addColumn('datastore_key', 'string', ['length' => 128, 'notnull' => false]);
            $table->setPrimaryKey(['tenant_id']);
        }

        if (!$schema->hasTable($this->t('ff_scim_users'))) {
            $table = $schema->createTable($this->t('ff_scim_users'));
            $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
            $table->addColumn('external_id', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('user_name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('display_name', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('given_name', 'string', ['length' => 128, 'notnull' => false]);
            $table->addColumn('family_name', 'string', ['length' => 128, 'notnull' => false]);
            $table->addColumn('active', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('emails', 'json', ['notnull' => true]);
            $table->addColumn('groups', 'json', ['notnull' => true]);
            $table->addColumn('roles', 'json', ['notnull' => true]);
            $table->addColumn('platform_role', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_name'], 'uniq_ff_scim_user_name');
            $table->addUniqueIndex(['external_id'], 'uniq_ff_scim_external_id');
        }

        if (!$schema->hasTable($this->t('ff_scim_groups'))) {
            $table = $schema->createTable($this->t('ff_scim_groups'));
            $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
            $table->addColumn('external_id', 'string', ['length' => 255, 'notnull' => false]);
            $table->addColumn('display_name', 'string', ['length' => 255, 'notnull' => true]);
            $table->addColumn('platform_role', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('member_ids', 'json', ['notnull' => true]);
            $table->addColumn('created_at', 'datetime', ['notnull' => true]);
            $table->addColumn('updated_at', 'datetime', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['external_id'], 'uniq_ff_scim_group_external_id');
        }
    }
};
