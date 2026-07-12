<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Audit';
    }

    public function id(): string
    {
        return 'audit.create_audit_saved_views';
    }

    public function description(): string
    {
        return 'Named, scope-bound saved filter sets for the audit consoles (F2)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('audit_saved_views'));
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => false]); // null = platform scope
        $table->addColumn('owner_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('filters', 'text', ['notnull' => true]);   // JSON query params
        $table->addColumn('created_at', 'string', ['length' => 40, 'notnull' => true]);

        $table->setPrimaryKey(['id']);
        // The one access path: "my views in this scope", newest first.
        $table->addIndex(['tenant_id', 'owner_id', 'created_at'], 'idx_audit_saved_views_owner');
    }
};
