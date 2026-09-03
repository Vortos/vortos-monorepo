<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Schedule\BackupScheduleType;

/**
 * R8-6 (A6/A9): declares the cadences of the whole backup lifecycle — backup, retention, drill — as
 * config, so an app never hand-writes a #[Scheduled] class. Entries are engine/environment-agnostic
 * here; {@see BackupConfig::build()} binds them to the configured engine + environment.
 */
final class ScheduleSetBuilder
{
    /** @var list<array{name: string, cron: string, type: BackupScheduleType, kind: BackupKind}> */
    private array $entries = [];

    private int $anon = 0;

    public function backup(string $cron, string|BackupKind $kind = BackupKind::LogicalFull, ?string $name = null): self
    {
        $resolved = $kind instanceof BackupKind ? $kind : BackupKind::from($kind);

        return $this->add($cron, BackupScheduleType::Backup, $resolved, $name);
    }

    public function retention(string $cron, ?string $name = null): self
    {
        return $this->add($cron, BackupScheduleType::Retention, BackupKind::LogicalFull, $name);
    }

    /**
     * @param string|BackupKind $kind which RESTORE PATH this drill proves.
     *
     * `logical_full` restores a dump — fast, standalone, and it needs no WAL at all.
     * `physical_base` restores a base backup and replays archived WAL on top of it, which is the
     * only drill that exercises the point-in-time chain.
     *
     * It matters that this is declared rather than inferred. Left to choose for itself the runner
     * takes the newest restorable artifact, and since logical dumps are taken far more often than
     * base backups, an installation running both would drill the dump every single time and never
     * once prove that WAL is replayable — while reporting a green restore drill throughout.
     */
    public function drill(
        string $cron,
        ?string $name = null,
        string|BackupKind $kind = BackupKind::LogicalFull,
    ): self {
        $resolved = $kind instanceof BackupKind ? $kind : BackupKind::from($kind);

        return $this->add($cron, BackupScheduleType::Drill, $resolved, $name);
    }

    /**
     * @return list<array{name: string, cron: string, type: BackupScheduleType, kind: BackupKind}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * The cron of the first declared backup entry, if any — the cadence retention derivation keys off.
     */
    public function firstBackupCron(): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['type'] === BackupScheduleType::Backup) {
                return $entry['cron'];
            }
        }

        return null;
    }

    private function add(string $cron, BackupScheduleType $type, BackupKind $kind, ?string $name): self
    {
        $this->entries[] = [
            'name' => $name ?? $this->defaultName($type),
            'cron' => $cron,
            'type' => $type,
            'kind' => $kind,
        ];

        return $this;
    }

    private function defaultName(BackupScheduleType $type): string
    {
        $suffix = $this->anon++ === 0 ? '' : '-' . $this->anon;

        return $type->value . $suffix;
    }
}
