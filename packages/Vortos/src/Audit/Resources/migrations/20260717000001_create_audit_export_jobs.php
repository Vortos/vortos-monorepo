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
        return 'audit.create_audit_export_jobs';
    }

    public function description(): string
    {
        return 'Async audit export jobs: lifecycle + object keys + attestable facts (P6)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('audit_export_jobs'));

        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('scope', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => false]); // null = platform scope
        $table->addColumn('requested_by_actor_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('requested_by_label', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('filter', 'text', ['notnull' => true]);   // JSON AuditExportFilter
        $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('record_count', 'integer', ['notnull' => false]);
        $table->addColumn('byte_size', 'bigint', ['notnull' => false]);
        $table->addColumn('content_sha256', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('body_key', 'string', ['length' => 512, 'notnull' => false]);
        $table->addColumn('manifest_key', 'string', ['length' => 512, 'notnull' => false]);
        $table->addColumn('error', 'text', ['notnull' => false]);
        $table->addColumn('created_at', 'string', ['length' => 40, 'notnull' => true]);
        $table->addColumn('updated_at', 'string', ['length' => 40, 'notnull' => true]);
        $table->addColumn('expires_at', 'string', ['length' => 40, 'notnull' => false]);

        $table->setPrimaryKey(['id']);

        // "Your exports in this scope, newest first" — the console list path.
        $table->addIndex(['scope', 'tenant_id', 'created_at'], 'idx_audit_export_jobs_scope');
        // The GC worklist: ready artifacts past their retention window.
        $table->addIndex(['status', 'expires_at'], 'idx_audit_export_jobs_expiry');
    }
};
