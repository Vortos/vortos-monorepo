<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Ses';
    }

    public function id(): string
    {
        return 'ses.outbox';
    }

    public function description(): string
    {
        return 'SES outbox — transactional email queue with delivery tracking and exponential backoff';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('aws_ses_outbox'));

        $table->addColumn('id',              'guid',               ['notnull' => true]);
        $table->addColumn('domain_event_id', 'guid',               ['notnull' => false]);
        $table->addColumn('status',          'string',             ['length' => 20, 'notnull' => true, 'default' => 'pending']);
        $table->addColumn('attempt_count',   'integer',            ['notnull' => true, 'default' => 0]);
        $table->addColumn('payload',         'json',               ['notnull' => true]);
        $table->addColumn('message_id',      'string',             ['length' => 255, 'notnull' => false]);
        $table->addColumn('last_error',      'text',               ['notnull' => false]);
        $table->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at',      'datetime_immutable', ['notnull' => true]);
        $table->addColumn('sent_at',         'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        // Prevents duplicate outbox entries for the same domain event (idempotent writes)
        $table->addUniqueIndex(['domain_event_id'], 'uq_aws_ses_outbox_domain_event_id');

        // Relay worker query: pending rows ready to process, skip locked for parallel workers
        $table->addIndex(['status', 'next_attempt_at'], 'idx_aws_ses_outbox_relay', [], [
            'where' => "status = 'pending'",
        ]);

        // Dashboard / monitoring: all rows for a given status
        $table->addIndex(['status', 'created_at'], 'idx_aws_ses_outbox_status_created');
    }
};
