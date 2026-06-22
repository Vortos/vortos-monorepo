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
        return 'feature_flags.add_change_requests';
    }

    public function description(): string
    {
        return 'Add change requests table for the 4-eyes approval workflow (Block 14)';
    }

    public function define(Schema $schema): void
    {
        if ($schema->hasTable($this->t('feature_flag_change_requests'))) {
            return;
        }

        $table = $schema->createTable($this->t('feature_flag_change_requests'));
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('flag_name', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('project_id', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('environment', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('change_type', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('payload', 'json', ['notnull' => true]);
        $table->addColumn('reason', 'text', ['notnull' => true]);
        $table->addColumn('requested_by', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('requested_at', 'datetime', ['notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'pending']);
        $table->addColumn('required_approvals', 'integer', ['notnull' => true, 'default' => 1]);
        $table->addColumn('approvals', 'json', ['notnull' => true]);
        $table->addColumn('rejections', 'json', ['notnull' => true]);
        $table->addColumn('apply_at', 'datetime', ['notnull' => false]);
        $table->addColumn('expires_at', 'datetime', ['notnull' => true]);
        $table->addColumn('applied_at', 'datetime', ['notnull' => false]);
        $table->addColumn('applied_by', 'string', ['length' => 255, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['flag_name', 'project_id', 'environment', 'status'], 'idx_cr_flag_scope_status');
        $table->addIndex(['status', 'apply_at'], 'idx_cr_status_apply_at');
        $table->addIndex(['status', 'expires_at'], 'idx_cr_status_expires_at');
    }
};
