<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Alerts';
    }

    public function id(): string
    {
        return 'alerts.create_uptime_streaks';
    }

    public function description(): string
    {
        return 'Create the consecutive-Unknown streak counter for the synthetic-uptime blind-detector meta-alert (Block 18)';
    }

    public function define(Schema $schema): void
    {
        $streaks = $schema->createTable($this->t('alerts_uptime_streaks'));
        $streaks->addColumn('monitor_id', 'string', ['length' => 255, 'notnull' => true]);
        $streaks->addColumn('streak', 'integer', ['notnull' => true]);
        $streaks->setPrimaryKey(['monitor_id']);
    }
};
