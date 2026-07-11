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
        return 'feature_flags.add_webhooks';
    }

    public function description(): string
    {
        return 'Add feature flag webhook subscriptions table (outbound flag-event notifications)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('feature_flag_webhooks'));
        $table->addColumn('id',          'string', ['length' => 36,  'notnull' => true]);
        $table->addColumn('url',         'string', ['length' => 2048, 'notnull' => true]);
        $table->addColumn('secret_hash', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('event_types', 'text',   ['notnull' => true]); // JSON array
        $table->addColumn('project_id',  'string', ['length' => 191, 'notnull' => false]);
        $table->addColumn('environment', 'string', ['length' => 32,  'notnull' => false]);
        $table->addColumn('active',      'boolean', ['notnull' => true, 'default' => true]);
        $table->addColumn('created_at',  'datetime_immutable', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['active'], 'idx_ff_webhooks_active');
    }
};
