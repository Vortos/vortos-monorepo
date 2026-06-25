<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Alerts';
    }

    public function id(): string
    {
        return 'alerts.create_alerts_state';
    }

    public function description(): string
    {
        return 'Create the dedupe/flap-damper state, ack, maintenance-silence, and notification audit tables (Block 17)';
    }

    public function define(Schema $schema): void
    {
        $state = $schema->createTable($this->t('alerts_state'));
        $state->addColumn('fingerprint', 'string', ['length' => 64, 'notnull' => true]);
        $state->addColumn('status', 'string', ['length' => 16, 'notnull' => true]);
        $state->addColumn('first_seen_at', 'string', ['length' => 32, 'notnull' => true]);
        $state->addColumn('last_seen_at', 'string', ['length' => 32, 'notnull' => true]);
        $state->addColumn('occurrence_count', 'integer', ['notnull' => true]);
        $state->addColumn('flap_transitions', 'integer', ['notnull' => true]);
        $state->addColumn('flap_window_start_at', 'string', ['length' => 32, 'notnull' => false]);
        $state->addColumn('flap_escalated_at', 'string', ['length' => 32, 'notnull' => false]);
        $state->setPrimaryKey(['fingerprint']);

        $acks = $schema->createTable($this->t('alerts_acks'));
        $acks->addColumn('fingerprint', 'string', ['length' => 64, 'notnull' => true]);
        $acks->addColumn('tier', 'integer', ['notnull' => true]);
        $acks->addColumn('acked_by', 'string', ['length' => 255, 'notnull' => true]);
        $acks->addColumn('acked_at', 'string', ['length' => 32, 'notnull' => true]);
        $acks->setPrimaryKey(['fingerprint']);

        $silences = $schema->createTable($this->t('alerts_silences'));
        $silences->addColumn('id', 'string', ['length' => 36, 'notnull' => true]);
        $silences->addColumn('rule_id', 'string', ['length' => 255, 'notnull' => true]);
        $silences->addColumn('starts_at', 'string', ['length' => 32, 'notnull' => true]);
        $silences->addColumn('expires_at', 'string', ['length' => 32, 'notnull' => true]);
        $silences->addColumn('created_by', 'string', ['length' => 255, 'notnull' => true]);
        $silences->addColumn('reason', 'text', ['notnull' => true]);
        $silences->setPrimaryKey(['id']);
        $silences->addIndex(['rule_id', 'expires_at'], 'idx_alerts_silences_rule_expiry');

        $audit = $schema->createTable($this->t('alerts_audit_log'));
        $audit->addColumn('entry_id', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('sequence', 'integer', ['notnull' => true]);
        $audit->addColumn('env', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('event_type', 'string', ['length' => 32, 'notnull' => true]);
        $audit->addColumn('fingerprint', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('actor_id', 'string', ['length' => 255, 'notnull' => true]);
        $audit->addColumn('occurred_at', 'string', ['length' => 32, 'notnull' => true]);
        $audit->addColumn('data', 'text', ['notnull' => true]);
        $audit->addColumn('prev_hash', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('content_hash', 'string', ['length' => 64, 'notnull' => true]);
        $audit->addColumn('signature', 'string', ['length' => 64, 'notnull' => true]);
        $audit->setPrimaryKey(['entry_id']);
        $audit->addUniqueIndex(['env', 'sequence'], 'uniq_alerts_audit_env_sequence');
        $audit->addIndex(['fingerprint'], 'idx_alerts_audit_fingerprint');
    }
};
