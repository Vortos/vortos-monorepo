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
        return 'paddle.webhook_idempotency';
    }

    public function description(): string
    {
        return 'Paddle webhook idempotency — deduplicates incoming Paddle webhook events by event ID';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable('paddle_webhook_idempotency');

        $table->addColumn('event_id',    'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('event_type',  'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('received_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('expires_at',  'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['event_id']);

        $table->addIndex(['expires_at'], 'idx_paddle_webhook_idempotency_expires');
    }
};
