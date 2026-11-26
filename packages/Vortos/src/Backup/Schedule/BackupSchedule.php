<?php

declare(strict_types=1);

namespace Vortos\Backup\Schedule;

use InvalidArgumentException;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryRole;

/**
 * A declarative backup schedule: "take this engine+kind backup, for this environment,
 * on this cron expression." The framework does not run a scheduler itself — these
 * generate a host cron / Supervisor fragment ({@see CronFragmentGenerator}) that
 * invokes `vortos backup:run`, the same way `vortos-docker` generates worker programs.
 */
final readonly class BackupSchedule
{
    public function __construct(
        public string $name,
        public DatabaseEngine $engine,
        public BackupKind $kind,
        public string $environment,
        public string $cron,
        // R8-6 (A6): which lifecycle verb this schedule drives. Defaults to Backup so pre-R8-6
        // call sites (which only ever meant `backup:run`) keep their exact meaning.
        public BackupScheduleType $type = BackupScheduleType::Backup,
        // Which pgBackRest repository a pgBackRest schedule acts on. Primary for everything else.
        public PgBackRestRepositoryRole $repository = PgBackRestRepositoryRole::Primary,
    ) {
        if ($name === '' || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Backup schedule name must be lower-kebab/snake: '{$name}'.");
        }
        if ($environment === '') {
            throw new InvalidArgumentException('Backup schedule environment must be non-empty.');
        }
        $this->assertCron($cron);
        if ($repository !== PgBackRestRepositoryRole::Primary && !$type->isPgBackRest()) {
            throw new InvalidArgumentException("Only a pgBackRest schedule can target the off-site repository: '{$name}'.");
        }
    }

    private function assertCron(string $cron): void
    {
        $fields = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException("Cron expression must have exactly 5 fields: '{$cron}'.");
        }
        foreach ($fields as $field) {
            if (preg_match('#^[\d*/,\-]+$#', $field) !== 1) {
                throw new InvalidArgumentException("Invalid cron field '{$field}' in '{$cron}'.");
            }
        }
    }
}
