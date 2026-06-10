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
        return 'paddle.webhook_inbox';
    }

    public function description(): string
    {
        return 'Paddle webhook inbox — verified webhooks are persisted here before any handler runs; a worker processes rows with retries and dead-lettering. The UNIQUE event_id constraint is the delivery idempotency check.';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('paddle_webhook_inbox'));

        $table->addColumn('id',                 'bigint',   ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('event_id',           'string',   ['length' => 191, 'notnull' => true]);
        $table->addColumn('event_type',         'string',   ['length' => 255, 'notnull' => true]);
        $table->addColumn('payload',            'json',     ['notnull' => true]);
        $table->addColumn('status',             'string',   ['length' => 20,  'notnull' => true, 'default' => 'pending']);
        $table->addColumn('attempts',           'smallint', ['notnull' => true, 'default' => 0]);
        $table->addColumn('completed_handlers', 'json',     ['notnull' => false]);
        $table->addColumn('last_error',         'text',     ['notnull' => false, 'default' => null]);
        $table->addColumn('occurred_at',        'datetime_immutable', ['notnull' => false]);
        $table->addColumn('received_at',        'datetime_immutable', ['notnull' => true]);
        $table->addColumn('processed_at',       'datetime_immutable', ['notnull' => false]);
        $table->addColumn('next_attempt_at',    'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['event_id'], 'uq_paddle_webhook_inbox_event_id');
        $table->addIndex(['status', 'next_attempt_at', 'received_at'], 'idx_paddle_webhook_inbox_worker');
    }
};
