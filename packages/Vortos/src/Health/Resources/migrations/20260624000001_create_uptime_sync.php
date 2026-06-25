<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Health';
    }

    public function id(): string
    {
        return 'health.create_uptime_sync';
    }

    public function description(): string
    {
        return 'Create the idempotency record for external uptime-monitor sync (Block 18)';
    }

    public function define(Schema $schema): void
    {
        $sync = $schema->createTable($this->t('uptime_sync'));
        $sync->addColumn('env', 'string', ['length' => 64, 'notnull' => true]);
        $sync->addColumn('journey_key', 'string', ['length' => 255, 'notnull' => true]);
        $sync->addColumn('payload_hash', 'string', ['length' => 64, 'notnull' => true]);
        $sync->addColumn('monitor_id', 'string', ['length' => 255, 'notnull' => true]);
        $sync->addColumn('applied_at', 'string', ['length' => 32, 'notnull' => true]);
        $sync->setPrimaryKey(['env', 'journey_key']);
    }
};
