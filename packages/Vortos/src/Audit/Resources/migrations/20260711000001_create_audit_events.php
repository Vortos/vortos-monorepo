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
        return 'audit.create_audit_events';
    }

    public function description(): string
    {
        return 'Create the unified append-only, hash-chained audit_events ledger (P2)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('audit_events'));

        // Domain event
        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);           // UuidV7
        $table->addColumn('scope', 'string', ['length' => 16, 'notnull' => true]);          // platform|tenant
        $table->addColumn('tenant_id', 'string', ['length' => 255, 'notnull' => false]);    // null for platform
        $table->addColumn('actor', 'text', ['notnull' => true]);                            // JSON (incl. impersonation chain)
        $table->addColumn('action', 'string', ['length' => 128, 'notnull' => true]);        // controlled vocabulary key
        $table->addColumn('target', 'text', ['notnull' => false]);                          // JSON, nullable
        $table->addColumn('sensitivity', 'string', ['length' => 16, 'notnull' => true]);    // low|normal|high
        $table->addColumn('outcome', 'string', ['length' => 16, 'notnull' => true]);        // allowed|denied|error
        $table->addColumn('source', 'text', ['notnull' => true]);                           // JSON (ip/ua/session/request/device)
        $table->addColumn('context', 'text', ['notnull' => true]);                          // JSON detail blob
        $table->addColumn('occurred_at', 'string', ['length' => 40, 'notnull' => true]);    // RFC3339, microsecond precision

        // Tamper-evidence anchors
        $table->addColumn('chain_key', 'string', ['length' => 255, 'notnull' => true]);     // 'platform' | 'tenant:{id}'
        $table->addColumn('sequence', 'bigint', ['notnull' => true]);                       // monotonic per chain_key
        $table->addColumn('prev_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('content_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('signature', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]); // '' when unsigned

        $table->setPrimaryKey(['id']);

        // THE tamper anchor: no two records may share a sequence within a chain, so a
        // concurrent append that read a stale tail loses the insert and must retry.
        $table->addUniqueIndex(['chain_key', 'sequence'], 'uq_audit_chain_seq');

        // Query + verification access paths
        $table->addIndex(['tenant_id', 'occurred_at'], 'idx_audit_tenant_time');   // per-tenant trail
        $table->addIndex(['scope', 'occurred_at'], 'idx_audit_scope_time');        // platform feed
        $table->addIndex(['action', 'occurred_at'], 'idx_audit_action_time');      // action filter
        $table->addIndex(['sensitivity', 'occurred_at'], 'idx_audit_sensitivity'); // "what admins did" / high-only
        $table->addIndex(['occurred_at'], 'idx_audit_occurred_at');                // retention sweep
    }
};
