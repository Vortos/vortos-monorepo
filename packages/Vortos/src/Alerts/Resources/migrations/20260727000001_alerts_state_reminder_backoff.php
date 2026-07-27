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
        return 'alerts.state_reminder_backoff';
    }

    public function description(): string
    {
        return 'Persist when an alert was last announced and how many reminders it has had, so the '
            . '"still firing" backoff survives a restart instead of resetting to full volume';
    }

    public function define(Schema $schema): void
    {
        // Alter-style provider: guarded so it publishes through the cumulative-schema diff and is a
        // no-op against a fresh schema that has not yet reached the create-alerts-state provider.
        if (!$schema->hasTable($this->t('alerts_state'))) {
            return;
        }

        $table = $schema->getTable($this->t('alerts_state'));

        // WHY THESE TWO COLUMNS
        //
        // "Still firing" reminders used to fire every Nth occurrence. Sources are evaluated on a
        // fixed cadence, so that is a fixed time interval that never widens — six permanently-open
        // alerts produced roughly thirty-six Slack messages an hour, indefinitely. People mute the
        // channel, and a muted channel silences the next real alert too.
        //
        // Backoff needs to know when someone was last actually TOLD (distinct from when the
        // condition was last OBSERVED, which moves every tick) and how many reminders have already
        // gone out. Without persisting both, every process restart resets the backoff and the
        // volume goes straight back to full — which on a blue/green deploy is several times a day.
        //
        // Nullable / defaulted because rows written before this column existed have no recorded
        // announcement. ReminderBackoff treats a null as "never announced", so such an alert
        // reminds once immediately and then backs off normally — the safe direction, since the
        // failure mode is one extra message rather than a missed one.
        if (!$table->hasColumn('last_notified_at')) {
            $table->addColumn('last_notified_at', 'string', ['length' => 32, 'notnull' => false]);
        }

        if (!$table->hasColumn('reminder_count')) {
            $table->addColumn('reminder_count', 'integer', ['notnull' => true, 'default' => 0]);
        }
    }
};
