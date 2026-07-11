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
        return 'audit.create_audit_checkpoints';
    }

    public function description(): string
    {
        return 'Create audit retention checkpoints (archival frontier per chain) (P4)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('audit_checkpoints'));

        $table->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('chain_key', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('last_sequence', 'bigint', ['notnull' => true]);
        $table->addColumn('last_content_hash', 'string', ['length' => 64, 'fixed' => true, 'notnull' => true]);
        $table->addColumn('archived_at', 'string', ['length' => 40, 'notnull' => true]);
        $table->addColumn('object_key', 'text', ['notnull' => true]);
        $table->addColumn('record_count', 'integer', ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // Frontier lookup: latest checkpoint per chain.
        $table->addIndex(['chain_key', 'last_sequence'], 'idx_audit_ckpt_chain_seq');
    }
};
