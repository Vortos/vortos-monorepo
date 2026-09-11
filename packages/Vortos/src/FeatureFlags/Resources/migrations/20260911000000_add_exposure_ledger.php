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
        return 'feature_flags.add_exposure_ledger';
    }

    public function description(): string
    {
        return 'Add the first-party flag exposure ledger and its daily rollup (complete, unsampled, consent-independent experiment measurement)';
    }

    public function define(Schema $schema): void
    {
        $this->defineLedger($schema);
        $this->defineRollup($schema);
    }

    /**
     * The raw ledger: one row per subject, per flag, per result, per UTC day.
     *
     * Short-lived by design — retention prunes it to a rolling window, because everything a
     * readout needs has been folded into the rollup by then. What survives in here is for
     * answering "is this pipeline actually working", not for statistics.
     */
    private function defineLedger(Schema $schema): void
    {
        $t = $schema->createTable($this->t('feature_flag_exposures'));

        // `date`, not a timestamp: the grain IS the day, and storing an instant would invite
        // a later query to group by something finer than the table can honestly support.
        $t->addColumn('day',           'date_immutable', ['notnull' => true]);

        // 32 hex characters — a truncated HMAC, never a user id. See SubjectPseudonymiser for
        // why it is keyed and why 128 bits is enough.
        $t->addColumn('subject_hash',  'string',  ['length' => 32,  'notnull' => true, 'fixed' => true]);

        $t->addColumn('flag',          'string',  ['length' => 191, 'notnull' => true]);

        // '' rather than NULL: this column is in the primary key, and Postgres does not treat
        // two NULLs as equal, so a nullable component would silently stop deduplicating the
        // exact case it exists to deduplicate.
        $t->addColumn('variant',       'string',  ['length' => 191, 'notnull' => true, 'default' => '']);

        $t->addColumn('source',        'string',  ['length' => 16,  'notnull' => true]);
        $t->addColumn('group_key',     'string',  ['length' => 191, 'notnull' => true, 'default' => '']);
        $t->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);

        // The primary key is the dedupe. It must mirror LedgerExposure::dedupeKey() exactly:
        // the Redis pre-filter fails open, so this is the only guarantee of the grain that
        // always holds.
        //
        // `day` leads deliberately. Retention deletes whole trailing days and the rollup
        // reads one day at a time, so both of the only two queries this table serves are
        // prefix scans on the primary key and need no second index. That matters more than
        // usual here: this is the highest-insert-rate table in the schema, and every extra
        // index is paid on every insert, in WAL, and again in every backup.
        $t->setPrimaryKey(['day', 'subject_hash', 'flag', 'variant']);
    }

    /**
     * The rollup: distinct subjects per day, flag, result, source and tenant.
     *
     * This is what a readout actually queries, and what is kept long enough to compare a
     * rollout against last quarter. It is derived data — it can always be rebuilt from the
     * ledger while the ledger still covers the window — but once the ledger is pruned this
     * is the only surviving record, so it is retained for years rather than days.
     */
    private function defineRollup(Schema $schema): void
    {
        $t = $schema->createTable($this->t('feature_flag_exposure_daily'));

        $t->addColumn('day',       'date_immutable', ['notnull' => true]);
        $t->addColumn('flag',      'string',  ['length' => 191, 'notnull' => true]);
        $t->addColumn('variant',   'string',  ['length' => 191, 'notnull' => true, 'default' => '']);
        $t->addColumn('source',    'string',  ['length' => 16,  'notnull' => true]);

        // '' is the real tenant-less case (an anonymous exposure, or a deployment that maps no
        // group), and it is a row like any other rather than an absence.
        $t->addColumn('group_key', 'string',  ['length' => 191, 'notnull' => true, 'default' => '']);

        // Distinct subjects, which is the denominator of every rate an experiment reports.
        // Deliberately not a count of exposures: at this grain they would be the same number,
        // and naming it `subjects` stops a later reader from assuming otherwise.
        $t->addColumn('subjects',  'integer', ['notnull' => true, 'default' => 0]);

        $t->addColumn('rolled_up_at', 'datetime_immutable', ['notnull' => true]);

        // Same shape of key, same reason — the rollup is idempotent and re-running a day must
        // update that day rather than duplicate it.
        $t->setPrimaryKey(['day', 'flag', 'variant', 'source', 'group_key']);
    }
};
