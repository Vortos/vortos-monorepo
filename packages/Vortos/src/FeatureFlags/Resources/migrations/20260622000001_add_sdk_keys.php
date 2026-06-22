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
        return 'feature_flags.add_sdk_keys';
    }

    public function description(): string
    {
        return 'Add SDK keys table for feature flags';
    }

    public function define(Schema $schema): void
    {
        if ($schema->hasTable($this->t('feature_flag_sdk_keys'))) {
            return;
        }

        $table = $schema->createTable($this->t('feature_flag_sdk_keys'));
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('name', 'string', ['length' => 100, 'notnull' => true]);
        $table->addColumn('key_prefix', 'string', ['length' => 12, 'notnull' => true]);
        $table->addColumn('key_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('kind', 'string', ['length' => 10, 'notnull' => true, 'default' => 'server']);
        $table->addColumn('project_id', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('environment', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);
        $table->addColumn('created_by', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('successor_key_id', 'string', ['length' => 36, 'notnull' => false]);
        $table->addColumn('grace_period_ends_at', 'datetime', ['notnull' => false]);
        $table->addColumn('expires_at', 'datetime', ['notnull' => false]);
        $table->addColumn('revoked_at', 'datetime', ['notnull' => false]);
        $table->addColumn('last_used_at', 'datetime', ['notnull' => false]);
        $table->addColumn('ip_allowlist', 'text', ['notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['key_hash'], 'uniq_sdk_key_hash');
        $table->addIndex(['key_prefix'], 'idx_sdk_key_prefix');
        $table->addIndex(['project_id', 'environment'], 'idx_sdk_key_project_env');
    }
};
