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
        return 'alerts.state_rule_id';
    }

    public function description(): string
    {
        return 'Record which rule each alert state belongs to, so inhibition can answer '
            . '"is this source rule currently firing?" — one root cause is one page, not fifty';
    }

    public function define(Schema $schema): void
    {
        // Alter-style provider: guarded so it publishes through the cumulative-schema diff and is a
        // no-op against a fresh schema that has not yet reached the create-alerts-state provider.
        if (!$schema->hasTable($this->t('alerts_state'))) {
            return;
        }

        $table = $schema->getTable($this->t('alerts_state'));

        // WHY THIS COLUMN
        //
        // State is keyed by fingerprint (rule + labels), so the same rule firing for two different
        // hosts is two rows. Inhibition asks a rule-level question — "is host-down firing anywhere
        // right now?" — which a fingerprint cannot answer on its own. Recording the rule id makes
        // that a single indexed lookup instead of an impossible reverse-hash.
        //
        // Nullable because rows written before this column existed carry no rule id. hasActiveRule
        // treats a null rule_id as "not this rule", so a pre-existing open alert simply does not
        // act as an inhibition source until it next fires and rewrites its row with the id — the
        // safe direction, since the failure mode is one page too many, never one suppressed.
        if (!$table->hasColumn('rule_id')) {
            $table->addColumn('rule_id', 'string', ['length' => 191, 'notnull' => false]);
        }

        // The inhibition lookup is (rule_id, status, last_seen_at); index the leading column so
        // "is this rule active" does not scan the table on every dispatch.
        if (!$table->hasIndex($this->t('alerts_state') . '_rule_id_idx')) {
            $table->addIndex(['rule_id'], $this->t('alerts_state') . '_rule_id_idx');
        }
    }
};
