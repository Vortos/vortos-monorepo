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
        return 'feature_flags.add_projects';
    }

    public function description(): string
    {
        return 'Add feature flag projects table and project_id to flags + segments (Block 11)';
    }

    public function define(Schema $schema): void
    {
        // Project registry table.
        $projects = $schema->createTable($this->t('feature_flag_projects'));
        $projects->addColumn('id',          'string',   ['length' => 36,  'notnull' => true]);
        $projects->addColumn('name',        'string',   ['length' => 191, 'notnull' => true]);
        $projects->addColumn('slug',        'string',   ['length' => 191, 'notnull' => true]);
        $projects->addColumn('description', 'text',     ['notnull' => false]);
        $projects->addColumn('created_at',  'datetime_immutable', ['notnull' => true]);
        $projects->addColumn('updated_at',  'datetime_immutable', ['notnull' => true]);
        $projects->setPrimaryKey(['id']);
        $projects->addUniqueIndex(['slug'], 'uq_ff_projects_slug');

        // project_id on feature_flags (back-compat default = 'default').
        if ($schema->hasTable($this->t('feature_flags'))) {
            $flags = $schema->getTable($this->t('feature_flags'));
            if (!$flags->hasColumn('project_id')) {
                $flags->addColumn('project_id', 'string', [
                    'length'  => 191,
                    'notnull' => true,
                    'default' => 'default',
                ]);
                $flags->addIndex(['project_id'], 'idx_ff_project_id');
            }
        }

        // project_id on feature_flag_segments (back-compat default = 'default').
        if ($schema->hasTable($this->t('feature_flag_segments'))) {
            $segments = $schema->getTable($this->t('feature_flag_segments'));
            if (!$segments->hasColumn('project_id')) {
                $segments->addColumn('project_id', 'string', [
                    'length'  => 191,
                    'notnull' => true,
                    'default' => 'default',
                ]);
                $segments->addIndex(['project_id'], 'idx_ff_segments_project_id');
            }
        }
    }
};
