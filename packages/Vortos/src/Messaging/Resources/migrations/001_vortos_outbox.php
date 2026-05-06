<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Messaging';
    }

    public function id(): string
    {
        return 'messaging.vortos_outbox';
    }

    public function description(): string
    {
        return 'Vortos outbox';
    }

    public function define(Schema $schema): void
    {
        $outbox = $schema->createTable('vortos_outbox');
        $outbox->addColumn('id', 'guid', ['notnull' => true]);
        $outbox->addColumn('transport_name', 'string', ['length' => 255, 'notnull' => true]);
        $outbox->addColumn('event_class', 'string', ['length' => 512, 'notnull' => true]);
        $outbox->addColumn('payload', 'text', ['notnull' => true]);
        $outbox->addColumn('headers', 'json', ['notnull' => true, 'default' => '{}']);
        $outbox->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'pending']);
        $outbox->addColumn('attempt_count', 'integer', ['notnull' => true, 'default' => 0]);
        $outbox->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $outbox->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('failure_reason', 'text', ['notnull' => false]);
        $outbox->setPrimaryKey(['id']);
        $outbox->addIndex(['status', 'created_at'], 'idx_vortos_outbox_status_created', [], [
            'where' => "status = 'pending'",
        ]);
    }
};
