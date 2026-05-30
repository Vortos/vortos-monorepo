<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Paddle';
    }

    public function id(): string
    {
        return 'paddle.audit_log';
    }

    public function description(): string
    {
        return 'Paddle audit log — immutable record of every Paddle webhook event received';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable('paddle_audit_log');

        $table->addColumn('id',              'bigint',  ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('event_type',      'string',  ['length' => 255, 'notnull' => true]);
        $table->addColumn('paddle_event_id', 'string',  ['length' => 255, 'notnull' => true]);
        $table->addColumn('entity_type',     'string',  ['length' => 100, 'notnull' => true]);
        $table->addColumn('entity_id',       'string',  ['length' => 255, 'notnull' => true]);
        $table->addColumn('actor',           'string',  ['length' => 255, 'notnull' => false]);
        $table->addColumn('occurred_at',     'datetime_immutable', ['notnull' => true]);
        $table->addColumn('recorded_at',     'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['entity_type', 'entity_id'], 'idx_paddle_audit_log_entity');
        $table->addIndex(['occurred_at'],              'idx_paddle_audit_log_occurred');
    }
};
