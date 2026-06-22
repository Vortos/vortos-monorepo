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
        return 'feature_flags.add_environment_protection';
    }

    public function description(): string
    {
        return 'Add per-environment change-request protection config + flag-level override (Block 14)';
    }

    public function define(Schema $schema): void
    {
        if (!$schema->hasTable($this->t('feature_flag_environment_protection'))) {
            $table = $schema->createTable($this->t('feature_flag_environment_protection'));
            $table->addColumn('environment', 'string', ['length' => 64, 'notnull' => true]);
            $table->addColumn('project_id', 'string', ['length' => 128, 'notnull' => true, 'default' => 'default']);
            $table->addColumn('protected', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('required_approvals', 'integer', ['notnull' => true, 'default' => 1]);
            $table->addColumn('require_reason', 'boolean', ['notnull' => true, 'default' => true]);
            $table->addColumn('request_ttl_seconds', 'integer', ['notnull' => true, 'default' => 604800]);
            $table->setPrimaryKey(['environment', 'project_id']);
        }

        $flags = $this->t('feature_flags');
        if ($schema->hasTable($flags)) {
            $flagsTable = $schema->getTable($flags);
            if (!$flagsTable->hasColumn('requires_approval')) {
                $flagsTable->addColumn('requires_approval', 'boolean', ['notnull' => false]);
            }
        }
    }
};
