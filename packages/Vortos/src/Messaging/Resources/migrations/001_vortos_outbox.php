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
        return 'Vortos outbox — envelope-first schema';
    }

    public function define(Schema $schema): void
    {
        $outbox = $schema->createTable($this->t('outbox'));

        $outbox->addColumn('id',                'guid',               ['notnull' => true]);
        $outbox->addColumn('transport_name',    'string',             ['length' => 255, 'notnull' => true]);
        $outbox->addColumn('event_id',          'guid',               ['notnull' => true]);
        $outbox->addColumn('aggregate_id',      'string',             ['length' => 255, 'notnull' => true]);
        $outbox->addColumn('aggregate_type',    'string',             ['length' => 512, 'notnull' => true]);
        $outbox->addColumn('aggregate_version', 'integer',            ['notnull' => true]);
        $outbox->addColumn('payload_type',      'string',             ['length' => 512, 'notnull' => true]);
        $outbox->addColumn('schema_version',    'integer',            ['notnull' => true, 'default' => 1]);
        $outbox->addColumn('occurred_at',       'datetime_immutable', ['notnull' => true]);
        $outbox->addColumn('correlation_id',    'string',             ['length' => 255, 'notnull' => false]);
        $outbox->addColumn('causation_id',      'string',             ['length' => 255, 'notnull' => false]);
        $outbox->addColumn('trace_id',          'string',             ['length' => 255, 'notnull' => false]);
        $outbox->addColumn('metadata',          'json',               ['notnull' => false]);
        $outbox->addColumn('payload',           'text',               ['notnull' => true]);
        $outbox->addColumn('status',            'string',             ['length' => 20,  'notnull' => true, 'default' => 'pending']);
        $outbox->addColumn('attempt_count',     'integer',            ['notnull' => true, 'default' => 0]);
        $outbox->addColumn('created_at',        'datetime_immutable', ['notnull' => true]);
        $outbox->addColumn('published_at',      'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('next_attempt_at',   'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('failure_reason',    'text',               ['notnull' => false]);

        $outbox->setPrimaryKey(['id']);

        // Relay worker: pending rows ordered by time, skip locked for parallelism
        $outbox->addIndex(['status', 'next_attempt_at'], 'idx_vortos_outbox_status_next', [], [
            'where' => "status = 'pending'",
        ]);

        // Replay by aggregate
        $outbox->addIndex(['aggregate_id', 'occurred_at'], 'idx_vortos_outbox_aggregate_occurred');

        // Replay by event type
        $outbox->addIndex(['payload_type', 'occurred_at'], 'idx_vortos_outbox_type_occurred');
    }
};
