<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Observability';
    }

    public function id(): string
    {
        return 'observability.create_deploy_audit_log';
    }

    public function description(): string
    {
        return 'Create the append-only, hash-chained deploy audit ledger (Block 16)';
    }

    public function define(Schema $schema): void
    {
        $table = $schema->createTable($this->t('observability_deploy_audit_log'));
        $table->addColumn('entry_id', 'string', ['length' => 36, 'notnull' => true]);
        $table->addColumn('sequence', 'integer', ['notnull' => true]);
        $table->addColumn('event_type', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('actor_identity_source', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('env', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('build_id', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('git_sha', 'string', ['length' => 40, 'notnull' => false]);
        $table->addColumn('image_digest', 'string', ['length' => 71, 'notnull' => false]);
        $table->addColumn('schema_fingerprint_id', 'string', ['length' => 71, 'notnull' => false]);
        $table->addColumn('reason', 'text', ['notnull' => false]);
        $table->addColumn('occurred_at', 'string', ['length' => 32, 'notnull' => true]);
        $table->addColumn('data', 'text', ['notnull' => true]);
        $table->addColumn('prev_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('content_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('signature', 'string', ['length' => 64, 'notnull' => true]);

        $table->setPrimaryKey(['entry_id']);
        $table->addUniqueIndex(['env', 'sequence'], 'uniq_observability_audit_env_sequence');
        $table->addIndex(['env', 'occurred_at'], 'idx_observability_audit_env_occurred');
        $table->addIndex(['build_id'], 'idx_observability_audit_build');
        $table->addIndex(['actor_id'], 'idx_observability_audit_actor');
    }
};
