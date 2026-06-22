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
        return 'feature_flags.create_feature_flags';
    }

    public function description(): string
    {
        return 'Create feature flags';
    }

    public function define(Schema $schema): void
    {
        $flags = $schema->createTable($this->t('feature_flags'));
        $flags->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $flags->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        $flags->addColumn('description', 'text', ['notnull' => true, 'default' => '']);
        $flags->addColumn('enabled', 'smallint', ['notnull' => true, 'default' => 0]);
        $flags->addColumn('rules', 'text', ['notnull' => true, 'default' => '[]']);
        $flags->addColumn('variants', 'text', ['notnull' => false]);
        $flags->addColumn('value_type', 'string', ['length' => 16, 'notnull' => true, 'default' => 'bool']);
        $flags->addColumn('default_value', 'text', ['notnull' => false]);
        $flags->addColumn('payload', 'text', ['notnull' => false]);
        $flags->addColumn('bucket_by', 'string', ['length' => 32, 'notnull' => true, 'default' => 'userId']);
        $flags->addColumn('kind', 'string', ['length' => 16, 'notnull' => true, 'default' => 'release']);
        $flags->addColumn('prerequisites', 'text', ['notnull' => false]);
        $flags->addColumn('variant_rules', 'text', ['notnull' => false]);
        $flags->addColumn('schedule', 'text', ['notnull' => false]);
        $flags->addColumn('required_scope', 'string', ['length' => 191, 'notnull' => false]);
        $flags->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $flags->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $flags->setPrimaryKey(['id']);
        $flags->addUniqueIndex(['name'], 'uniq_feature_flags_name');

        // Block 9 — per-tenant flag overrides (above the global flag state).
        $overrides = $schema->createTable($this->t('feature_flag_tenant_overrides'));
        $overrides->addColumn('tenant_id', 'string', ['length' => 191, 'notnull' => true]);
        $overrides->addColumn('flag_name', 'string', ['length' => 255, 'notnull' => true]);
        $overrides->addColumn('override_json', 'text', ['notnull' => true]);
        $overrides->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $overrides->setPrimaryKey(['tenant_id', 'flag_name']);

        // Block 7 — CQRS read models, in the same relational DB (no second datastore).
        // Block 10 additions: `environment` column on audit_log; composite PK on state_view.
        $audit = $schema->createTable($this->t('feature_flag_audit_log'));
        $audit->addColumn('event_id',    'string', ['length' => 36,  'notnull' => true]);
        $audit->addColumn('flag_id',     'string', ['length' => 36,  'notnull' => true]);
        $audit->addColumn('flag_name',   'string', ['length' => 255, 'notnull' => true]);
        $audit->addColumn('environment', 'string', ['length' => 64,  'notnull' => true, 'default' => 'production']);
        $audit->addColumn('event_type',  'string', ['length' => 64,  'notnull' => true]);
        $audit->addColumn('actor_id',    'string', ['length' => 191, 'notnull' => true, 'default' => '']);
        $audit->addColumn('reason',      'text',   ['notnull' => false]);
        $audit->addColumn('occurred_at', 'string', ['length' => 40,  'notnull' => true, 'default' => '']);
        $audit->addColumn('data',        'text',   ['notnull' => true, 'default' => '{}']);
        $audit->setPrimaryKey(['event_id']);
        $audit->addIndex(['flag_name', 'environment', 'occurred_at'], 'idx_ff_audit_flag_env_time');
        $audit->addIndex(['flag_id'],                                  'idx_ff_audit_flag_id');

        $stateView = $schema->createTable($this->t('feature_flag_state_view'));
        $stateView->addColumn('environment',     'string',  ['length' => 64,  'notnull' => true, 'default' => 'production']);
        $stateView->addColumn('flag_name',       'string',  ['length' => 255, 'notnull' => true]);
        $stateView->addColumn('flag_id',         'string',  ['length' => 36,  'notnull' => true]);
        $stateView->addColumn('enabled',         'smallint', ['notnull' => true, 'default' => 0]);
        $stateView->addColumn('archived',        'smallint', ['notnull' => true, 'default' => 0]);
        $stateView->addColumn('value_type',      'string',  ['length' => 16,  'notnull' => true, 'default' => 'bool']);
        $stateView->addColumn('kind',            'string',  ['length' => 16,  'notnull' => true, 'default' => 'release']);
        $stateView->addColumn('rule_count',      'integer', ['notnull' => true, 'default' => 0]);
        $stateView->addColumn('variants',        'text',    ['notnull' => false]);
        $stateView->addColumn('scheduled',       'smallint', ['notnull' => true, 'default' => 0]);
        $stateView->addColumn('last_event_type', 'string',  ['length' => 64,  'notnull' => true, 'default' => '']);
        $stateView->addColumn('last_actor_id',   'string',  ['length' => 191, 'notnull' => true, 'default' => '']);
        $stateView->addColumn('updated_at',      'string',  ['length' => 40,  'notnull' => true, 'default' => '']);
        $stateView->setPrimaryKey(['environment', 'flag_name']);
        $stateView->addUniqueIndex(['environment', 'flag_name'], 'uniq_ff_state_view_env_name');
        $stateView->addIndex(['flag_id'],                         'idx_ff_state_view_flag_id');

        $segments = $schema->createTable($this->t('feature_flag_segments'));
        $segments->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $segments->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        $segments->addColumn('description', 'text', ['notnull' => true, 'default' => '']);
        $segments->addColumn('rules', 'text', ['notnull' => true, 'default' => '[]']);
        $segments->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $segments->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $segments->setPrimaryKey(['id']);
        $segments->addUniqueIndex(['name'], 'uniq_feature_flag_segments_name');
    }
};
