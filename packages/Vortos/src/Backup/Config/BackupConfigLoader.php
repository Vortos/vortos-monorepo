<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use InvalidArgumentException;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Environment\DefaultEnvironment;
use Vortos\Backup\Pitr\WalCompression;
use Vortos\Backup\Pitr\WalCompressionSettings;
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

    /**
     * The store WAL segments belong in, or null when they share the primary one.
     *
     * The env fallback exists so WAL can be split off without editing config/backup.php — useful
     * precisely when the reason for splitting is that the primary bucket turned out to be immutable.
     */
    public function walStoreKey(?string $envFallback): ?string
    {
        $configured = $this->config()?->walStoreKeyValue();

        if ($configured !== null) {
            return $configured;
        }

        $fallback = $envFallback !== null ? trim($envFallback) : '';

        return $fallback === '' ? null : $fallback;
    }

    /**
     * The codec WAL segments are compressed with before shipping.
     *
     * The env fallback mirrors {@see walStoreKey()}: it exists so an operator staring at a storage
     * bill can switch compression on without a release. Values are the {@see CompressionCodec}
     * names; anything unrecognised throws rather than defaulting to 'none', because a typo that
     * silently disables compression reproduces the exact fault this setting was added to fix.
     */
    public function walCompression(?string $codecFallback, ?string $levelFallback): WalCompressionSettings
    {
        $config = $this->config();

        $codec = $config?->walCodecValue() ?? CompressionCodec::None;
        $level = $config?->walCompressionLevelValue() ?? WalCompression::DEFAULT_LEVEL;

        $codecOverride = $codecFallback !== null ? trim($codecFallback) : '';
        if ($codecOverride !== '') {
            $codec = CompressionCodec::tryFrom(strtolower($codecOverride)) ?? throw new InvalidArgumentException(sprintf(
                "Unknown WAL compression codec '%s'. Expected one of: none, gzip.",
                $codecOverride,
            ));
        }

        $levelOverride = $levelFallback !== null ? trim($levelFallback) : '';
        if ($levelOverride !== '') {
            if (!ctype_digit($levelOverride) || (int) $levelOverride < 1 || (int) $levelOverride > 9) {
                throw new InvalidArgumentException("WAL compression level must be an integer 1-9, got '{$levelOverride}'.");
            }
            $level = (int) $levelOverride;
        }

        // Constructor re-asserts codec support, so an unsupported codec reaching this from either
        // source fails at boot rather than inside archive_command.
        return new WalCompressionSettings($codec, $level);
    }

    /**
     * The environment label backups are catalogued under.
     *
     * Anything that *reads* the catalog must ask here rather than reaching for APP_ENV. The two are
     * not the same value and are not meant to be: {@see DefaultEnvironment} standardises the backup
     * label on 'production' to match the deploy and release manifests, while APP_ENV is 'prod'.
     * Reading the wrong one silently matches no rows, which is how the freshness gauge came to
     * report "no backup at all" on an installation with thousands of catalogued artifacts.
     */
    public function environment(): string
    {
        return $this->config()?->environmentValue() ?? DefaultEnvironment::NAME;
    }

    /**
     * The engines that actually have backups configured.
     *
     * @return list<DatabaseEngine>
     */
    public function engines(): array
    {
        $engine = $this->config()?->engineOrNull();

        // Without a config file there is nothing to narrow by, so both supported engines are
        // reported and a missing one shows as backup_present=0 — the honest answer to "is anything
        // backing this up". With a config, reporting an engine the app never configured invents a
        // permanently-red series for a database that does not exist.
        return $engine !== null ? [$engine] : [DatabaseEngine::Postgres, DatabaseEngine::Mongo];
    }
}
