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
        return 'audit.create_audit_chain_heads';
    }

    public function description(): string
    {
        return 'Per-chain head row anchoring the portable RowChainLock append lock (F1)';
    }

    public function define(Schema $schema): void
    {
        // One row per hash chain. Exists solely as a SELECT ... FOR UPDATE lock anchor for
        // the portable (non-Postgres) append-lock strategy; the authoritative chain tail is
        // still derived from audit_events. On Postgres the advisory-lock strategy is used
        // instead and this table simply stays empty.
        $table = $schema->createTable($this->t('audit_chain_heads'));
        $table->addColumn('chain_key', 'string', ['length' => 255, 'notnull' => true]);
        $table->setPrimaryKey(['chain_key']);
    }
};
