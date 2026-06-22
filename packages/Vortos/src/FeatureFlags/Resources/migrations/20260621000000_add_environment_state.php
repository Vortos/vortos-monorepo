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
        return 'feature_flags.add_environment_state';
    }

    public function description(): string
    {
        return 'Add per-environment flag state table (Block 10)';
    }

    public function define(Schema $schema): void
    {
        // Per-environment mutable flag state (the "state" half of the definition/state split).
        // PK (flag_id, environment); the hot read path is `findAllForEnv()` which filters on
        // `environment`. Back-compat: existing Phase A/B flags have no row here in non-production
        // environments — the resolver falls back to the legacy definition row for 'production'.
        $env = $schema->createTable($this->t('feature_flag_environment_state'));
        $env->addColumn('flag_id',        'string',  ['length' => 36,  'notnull' => true]);
        $env->addColumn('environment',    'string',  ['length' => 64,  'notnull' => true]);
        $env->addColumn('enabled',        'smallint', ['notnull' => true, 'default' => 0]);
        $env->addColumn('rules',          'text',    ['notnull' => true, 'default' => '[]']);
        $env->addColumn('variants',       'text',    ['notnull' => false]);
        $env->addColumn('variant_rules',  'text',    ['notnull' => false]);
        $env->addColumn('schedule',       'text',    ['notnull' => false]);
        $env->addColumn('payload',        'text',    ['notnull' => false]);
        $env->addColumn('required_scope', 'string',  ['length' => 191, 'notnull' => false]);
        $env->addColumn('prerequisites',  'text',    ['notnull' => false]);
        $env->addColumn('updated_at',     'datetime_immutable', ['notnull' => true]);
        $env->setPrimaryKey(['flag_id', 'environment']);
        $env->addIndex(['environment', 'flag_id'], 'idx_ff_env_state_env');
    }
};
