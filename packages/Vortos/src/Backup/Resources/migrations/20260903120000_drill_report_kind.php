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
        return 'backup.drill_report_kind';
    }

    public function description(): string
    {
        return 'Record which restore path a drill proved (logical dump vs point-in-time base+WAL)';
    }

    public function define(Schema $schema): void
    {
        // Guarded like every other alter-style provider in this framework: at publish time define()
        // runs against a fresh Schema in which the base table does not exist, and an unguarded
        // getTable() throws and aborts the whole publish run.
        if (!$schema->hasTable($this->t('backup_drill_report'))) {
            return;
        }

        $drill = $schema->getTable($this->t('backup_drill_report'));

        if ($drill->hasColumn('kind')) {
            return;
        }

        // NULLABLE, and it stays nullable: this is an expand-only migration, and the rows already in
        // the table were written before a drill could be anything but a logical restore. Backfilling
        // them with 'logical_full' would be a guess presented as a record, and the honest reading of
        // a historical row is that it does not say.
        //
        // No index. The table gains a handful of rows a week, and the existing
        // (engine, environment, started_at) index already carries every query that filters on kind —
        // while a new one would have to be created CONCURRENTLY to pass the migration analyzer, for
        // no measurable benefit.
        $drill->addColumn('kind', 'string', ['length' => 32, 'notnull' => false]);
    }
};
