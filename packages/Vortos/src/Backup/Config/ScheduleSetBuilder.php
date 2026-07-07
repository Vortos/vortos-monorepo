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

    public function drill(string $cron, ?string $name = null): self
    {
        return $this->add($cron, BackupScheduleType::Drill, BackupKind::LogicalFull, $name);
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
