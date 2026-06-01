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
        return 'paddle.outbox';
    }

    public function description(): string
    {
        return 'Paddle outbox — reliable async queue for outbound Paddle API calls with status tracking, error visibility, and configurable retry';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('paddle_outbox'));

        $table->addColumn('id',               'bigint',   ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('operation',        'string',   ['length' => 255, 'notnull' => true]);
        $table->addColumn('payload',          'json',     ['notnull' => true]);
        $table->addColumn('idempotency_key',  'string',   ['length' => 36,  'notnull' => true]);
        $table->addColumn('status',           'string',   ['length' => 20,  'notnull' => true, 'default' => 'pending']);
        $table->addColumn('attempts',         'smallint', ['notnull' => true, 'default' => 0]);
        $table->addColumn('last_error',       'text',     ['notnull' => false, 'default' => null]);
        $table->addColumn('last_attempted_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('next_attempt_at',  'datetime_immutable', ['notnull' => true]);
        $table->addColumn('failed_at',        'datetime_immutable', ['notnull' => false]);
        $table->addColumn('delivered_at',     'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at',       'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['idempotency_key'], 'uq_paddle_outbox_idempotency_key');
        $table->addIndex(['status', 'next_attempt_at', 'created_at'], 'idx_paddle_outbox_relay');
    }
};
