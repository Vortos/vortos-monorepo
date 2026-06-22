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
        return 'feature_flags.add_layers';
    }

    public function description(): string
    {
        return 'Add mutual-exclusion experiment layers table (Block 30)';
    }

    public function define(Schema $schema): void
    {
        if ($schema->hasTable($this->t('ff_layers'))) {
            return;
        }

        $table = $schema->createTable($this->t('ff_layers'));
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('name', 'string', ['length' => 128, 'notnull' => true]);
        $table->addColumn('salt', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('holdout_weight', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('project_id', 'string', ['length' => 128, 'notnull' => true, 'default' => 'default']);
        $table->addColumn('members', 'json', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['name'], 'uniq_ff_layer_name');
        $table->addIndex(['project_id'], 'idx_ff_layer_project');
    }
};
