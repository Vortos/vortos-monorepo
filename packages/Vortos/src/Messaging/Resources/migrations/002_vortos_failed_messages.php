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
        return 'messaging.vortos_failed_messages';
    }

    public function description(): string
    {
        return 'Vortos failed messages';
    }

    public function define(Schema $schema): void
    {
        $failed = $schema->createTable('vortos_failed_messages');
        $failed->addColumn('id', 'guid', ['notnull' => true]);
        $failed->addColumn('transport_name', 'string', ['length' => 255, 'notnull' => true]);
        $failed->addColumn('event_class', 'string', ['length' => 512, 'notnull' => true]);
        $failed->addColumn('handler_id', 'string', ['length' => 512, 'notnull' => true]);
        $failed->addColumn('payload', 'text', ['notnull' => true]);
        $failed->addColumn('headers', 'json', ['notnull' => true, 'default' => '{}']);
        $failed->addColumn('failure_reason', 'text', ['notnull' => true]);
        $failed->addColumn('exception_class', 'string', ['length' => 512, 'notnull' => true]);
        $failed->addColumn('attempt_count', 'integer', ['notnull' => true, 'default' => 0]);
        $failed->addColumn('failed_at', 'datetime_immutable', ['notnull' => true]);
        $failed->addColumn('replayed_at', 'datetime_immutable', ['notnull' => false]);
        $failed->addColumn('status', 'string', ['length' => 20, 'notnull' => true, 'default' => 'failed']);
        $failed->setPrimaryKey(['id']);
        $failed->addIndex(['status', 'failed_at'], 'idx_vortos_failed_messages_status');
        $failed->addIndex(['status', 'transport_name', 'event_class'], 'idx_vortos_failed_messages_status_transport_event');
        $failed->addIndex(['transport_name', 'failed_at'], 'idx_vortos_failed_messages_transport_failed_at');
    }
};
