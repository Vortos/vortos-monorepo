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
        return 'feature_flags.create_feature_flags';
    }

    public function description(): string
    {
        return 'Create feature flags';
    }

    public function define(Schema $schema): void
    {
        $flags = $schema->createTable('feature_flags');
        $flags->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $flags->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        $flags->addColumn('description', 'text', ['notnull' => true, 'default' => '']);
        $flags->addColumn('enabled', 'smallint', ['notnull' => true, 'default' => 0]);
        $flags->addColumn('rules', 'text', ['notnull' => true, 'default' => '[]']);
        $flags->addColumn('variants', 'text', ['notnull' => false]);
        $flags->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $flags->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $flags->setPrimaryKey(['id']);
        $flags->addUniqueIndex(['name'], 'uniq_feature_flags_name');
    }
};
