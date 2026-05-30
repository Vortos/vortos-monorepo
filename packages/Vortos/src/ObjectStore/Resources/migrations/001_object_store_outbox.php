<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'ObjectStore';
    }

    public function id(): string
    {
        return 'object_store.outbox';
    }

    public function description(): string
    {
        return 'Object store outbox — tracks async object store operations for reliable processing';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('object_store_outbox'));

        $table->addColumn('id',               'guid',               ['notnull' => true]);
        $table->addColumn('domain_event_id',  'string',             ['length' => 255, 'notnull' => false, 'default' => null]);
        $table->addColumn('operation',        'string',             ['length' => 64,  'notnull' => true]);
        $table->addColumn('status',           'string',             ['length' => 32,  'notnull' => true]);
        $table->addColumn('attempt_count',    'integer',            ['notnull' => true, 'default' => 0]);
        $table->addColumn('payload',          'json',               ['notnull' => true]);
        $table->addColumn('last_error',       'text',               ['notnull' => false, 'default' => null]);
        $table->addColumn('next_attempt_at',  'datetime_immutable', ['notnull' => false, 'default' => null]);
        $table->addColumn('created_at',       'datetime_immutable', ['notnull' => true]);
        $table->addColumn('processed_at',     'datetime_immutable', ['notnull' => false, 'default' => null]);

        $table->setPrimaryKey(['id']);

        $table->addUniqueIndex(['domain_event_id'], 'uq_object_store_outbox_domain_event_id');

        $table->addIndex(['status', 'next_attempt_at', 'created_at'], 'idx_object_store_outbox_pending');
    }
};
