<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Schedule\BackupScheduleRegistry;

/**
 * Loads `config/backup.php` (then the env-specific `config/{env}/backup.php` override) and exposes the
 * resulting {@see BackupConfig} to the container as concrete services — the same "framework provides,
 * app configures" contract as `config/scheduler.php`. Each file simply `return`s a BackupConfig.
 *
 * Loading is deferred to runtime (a factory), so the real project dir / env are available and a
 * malformed config fails loudly at boot rather than being silently ignored.
 */
final class BackupConfigLoader
{
    private ?BackupConfig $cached = null;
    private bool $loaded = false;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $env = 'prod',
    ) {
    }

    public function config(): ?BackupConfig
    {
        if ($this->loaded) {
            return $this->cached;
        }

        $this->loaded = true;
        $config = null;

        foreach ([$this->projectDir . '/config/backup.php', $this->projectDir . '/config/' . $this->env . '/backup.php'] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $returned = require $file;
            if (!$returned instanceof BackupConfig) {
                throw new \RuntimeException(sprintf(
                    '%s must `return BackupConfig::create()->…`, got %s.',
                    $file,
                    get_debug_type($returned),
                ));
            }

            $config = $returned;
        }

        $this->cached = $config;

        return $config;
    }

    /** @return list<\Vortos\Backup\Schedule\BackupSchedule> */
    public function schedules(): array
    {
        return $this->config()?->buildSchedules() ?? [];
    }

    public function scheduleRegistry(): BackupScheduleRegistry
    {
        return new BackupScheduleRegistry($this->schedules());
    }

    /**
     * The effective retention policy — from config/backup.php when present (with cadence-derived
     * hourly), else the framework default {@see RetentionPolicy}.
     */
    public function retentionPolicy(): RetentionPolicy
    {
        return $this->config()?->buildRetentionPolicy() ?? new RetentionPolicy();
    }

    public function storeKey(?string $envFallback): string
    {
        return $this->config()?->storeKeyValue() ?? ($envFallback ?? 'object-store');
    }

    public function keyPrefix(?string $envFallback): string
    {
        return $this->config()?->keyPrefixValue() ?? ($envFallback ?? 'backups');
    }
}
