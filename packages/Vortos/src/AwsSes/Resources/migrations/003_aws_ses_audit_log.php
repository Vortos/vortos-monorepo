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
        return 'ses.audit_log';
    }

    public function description(): string
    {
        return 'SES audit log — immutable record of every successfully sent email';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable('aws_ses_audit_log');

        $table->addColumn('id',           'guid',               ['notnull' => true]);
        $table->addColumn('message_id',   'string',             ['length' => 255, 'notnull' => true]);
        $table->addColumn('outbox_id',    'guid',               ['notnull' => false]);
        $table->addColumn('recipients',   'text',               ['notnull' => true]);
        $table->addColumn('subject',      'string',             ['length' => 998, 'notnull' => true]);
        $table->addColumn('driver',       'string',             ['length' => 20,  'notnull' => true]);
        $table->addColumn('region',       'string',             ['length' => 50,  'notnull' => false]);
        $table->addColumn('sent_at',      'datetime_immutable', ['notnull' => true]);
        $table->addColumn('created_at',   'datetime_immutable', ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // Lookup by SES message ID (for bounce/complaint correlation)
        $table->addUniqueIndex(['message_id'], 'uq_aws_ses_audit_log_message_id');

        // Lookup by outbox row (for relay correlation)
        $table->addIndex(['outbox_id'],         'idx_aws_ses_audit_log_outbox');
        $table->addIndex(['sent_at'],            'idx_aws_ses_audit_log_sent_at');
    }
};
