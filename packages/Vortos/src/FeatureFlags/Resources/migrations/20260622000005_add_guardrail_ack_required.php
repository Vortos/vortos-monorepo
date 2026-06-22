<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'FeatureFlags';
    }

    public function id(): string
    {
        return 'feature_flags.add_guardrail_ack_required';
    }

    public function description(): string
    {
        return 'Add ack_required column to guardrail policies for manual-acknowledgement mode';
    }

    public function define(Schema $schema): void
    {
        $tableName = $this->t('feature_flag_guardrail_policies');

        if (!$schema->hasTable($tableName)) {
            return;
        }

        $table = $schema->getTable($tableName);

        if (!$table->hasColumn('ack_required')) {
            $table->addColumn('ack_required', 'boolean', ['notnull' => true, 'default' => false]);
        }
    }
};
