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
        return 'feature_flags.add_guardrail_policies';
    }

    public function description(): string
    {
        return 'Add release guardrail policies table for automated rollback (Block 15)';
    }

    public function define(Schema $schema): void
    {
        if ($schema->hasTable($this->t('feature_flag_guardrail_policies'))) {
            return;
        }

        $table = $schema->createTable($this->t('feature_flag_guardrail_policies'));
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('flag_name', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('project_id', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('environment', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 16, 'notnull' => true, 'default' => 'watching']);
        $table->addColumn('action', 'string', ['length' => 16, 'notnull' => true, 'default' => 'disable']);
        $table->addColumn('pause_ramp_target_pct', 'smallint', ['notnull' => false]);
        $table->addColumn('consecutive_windows', 'integer', ['notnull' => true, 'default' => 2]);
        $table->addColumn('window_seconds', 'integer', ['notnull' => true, 'default' => 300]);
        $table->addColumn('cooldown_seconds', 'integer', ['notnull' => true, 'default' => 600]);
        $table->addColumn('enabled', 'boolean', ['notnull' => true, 'default' => true]);
        $table->addColumn('consecutive_breach_count', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('conditions', 'json', ['notnull' => true]);
        $table->addColumn('last_evaluated_at', 'datetime', ['notnull' => false]);
        $table->addColumn('triggered_at', 'datetime', ['notnull' => false]);
        $table->addColumn('resolved_at', 'datetime', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime', ['notnull' => true]);
        $table->addColumn('created_by', 'string', ['length' => 255, 'notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['flag_name', 'project_id', 'environment'], 'idx_guardrail_flag_scope');
        $table->addIndex(['status', 'enabled', 'last_evaluated_at'], 'idx_guardrail_due');
    }
};
