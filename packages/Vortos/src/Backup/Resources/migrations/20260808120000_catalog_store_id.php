<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Vortos\Migration\Schema\AbstractModuleSchemaProvider;

return new class extends AbstractModuleSchemaProvider {
    public function module(): string
    {
        return 'Backup';
    }

    public function id(): string
    {
        return 'backup.catalog_store_id';
    }

    public function description(): string
    {
        return 'Record which store holds each artifact, so WAL can live in a bucket of its own';
    }

    public function define(Schema $schema): void
    {
        // Alter-style provider: guarded so it is a no-op against a fresh schema that has not reached
        // the create-catalog provider yet, and idempotent on re-publish.
        if (!$schema->hasTable($this->t('backup_catalog'))) {
            return;
        }

        $table = $schema->getTable($this->t('backup_catalog'));

        if ($table->hasColumn('store_id')) {
            return;
        }

        // WHY THIS COLUMN EXISTS
        //
        // Restore points and WAL want opposite guarantees from a bucket. An Object Lock (WORM) bucket
        // is exactly right for a base backup — immutable is the point when someone is trying to
        // destroy your data — and exactly wrong for WAL, where a segment lands every few minutes and
        // retention's whole job is to prune the ones older than the oldest retained base. Object Lock
        // is bucket-level with no per-prefix exemption, so satisfying both needs two buckets.
        //
        // Once there can be two, "where is this artifact" stops being answerable from the artifact.
        // `store_key` is the key WITHIN a store, not the store itself. Inferring the store from the
        // kind instead would work right up until the configured WAL store changes, at which point
        // every segment already shipped would be looked for somewhere that never held it — discovered
        // during a restore.
        //
        // NULLABLE, and never backfilled. NULL is the truthful value: a row written before this column
        // existed is in the primary store because that was the only store there was. It also could not
        // be backfilled if we wanted to — `trg_backup_catalog_no_update` forbids UPDATE on this table.
        $table->addColumn('store_id', 'string', ['length' => 64, 'notnull' => false]);
    }
};
