<?php

declare(strict_types=1);

namespace Vortos\Backup\Config;

use Closure;
use InvalidArgumentException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Environment\DefaultEnvironment;
use Vortos\Backup\Schedule\BackupSchedule;

/**
 * R8-6 (A6–A9): the single fluent surface an app uses to declare its whole backup lifecycle as
 * configuration — engine, store, cadences (backup/retention/drill), retention policy, and alerting —
 * mirroring `config/scheduler.php` → VortosSchedulerConfig. The framework owns the runtime (the
 * dedicated backup worker); the app owns only this file:
 *
 *   return BackupConfig::create()
 *       ->engine('postgres')
 *       ->store('object-store')->keyPrefix('backups')
 *       ->schedule(fn ($s) => $s
 *           ->backup('0 *​/6 * * *', kind: 'logical_full')
 *           ->retention('0 3 * * *')
 *           ->drill('0 4 * * 0'))
 *       ->retention(fn ($r) => $r->hourly(8)->daily(7)->weekly(4)->monthly(6)->maxAgeDays(90))
 *       ->alerts(fn ($a) => $a->onFailure()->channel('slack'));
 */
final class BackupConfig
{
    private ?DatabaseEngine $engine = null;
    private string $storeKey = 'object-store';
    private string $keyPrefix = 'backups';
    private string $environment = DefaultEnvironment::NAME;
    private ScheduleSetBuilder $schedules;
    private RetentionBuilder $retention;
    private AlertsBuilder $alerts;

    public function __construct()
    {
        $this->schedules = new ScheduleSetBuilder();
        $this->retention = new RetentionBuilder();
        $this->alerts = new AlertsBuilder();
    }

    public static function create(): self
    {
        return new self();
    }

    public function engine(string|DatabaseEngine $engine): self
    {
        $resolved = $engine instanceof DatabaseEngine ? $engine : DatabaseEngine::tryFrom($engine);
        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf(
                'Unknown backup engine "%s". Known: %s.',
                (string) $engine,
                implode(', ', DatabaseEngine::all()),
            ));
        }
        $this->engine = $resolved;

        return $this;
    }

    public function store(string $storeKey): self
    {
        if ($storeKey === '') {
            throw new InvalidArgumentException('Backup store key must not be empty.');
        }
        $this->storeKey = $storeKey;

        return $this;
    }

    public function keyPrefix(string $keyPrefix): self
    {
        $this->keyPrefix = $keyPrefix;

        return $this;
    }

    public function environment(string $environment): self
    {
        if ($environment === '') {
            throw new InvalidArgumentException('Backup environment must not be empty.');
        }
        $this->environment = $environment;

        return $this;
    }

    /** @param Closure(ScheduleSetBuilder): mixed $configure */
    public function schedule(Closure $configure): self
    {
        $configure($this->schedules);

        return $this;
    }

    /** @param Closure(RetentionBuilder): mixed $configure */
    public function retention(Closure $configure): self
    {
        $configure($this->retention);

        return $this;
    }

    /** @param Closure(AlertsBuilder): mixed $configure */
    public function alerts(Closure $configure): self
    {
        $configure($this->alerts);

        return $this;
    }

    public function engineOrNull(): ?DatabaseEngine
    {
        return $this->engine;
    }

    public function resolvedEngine(): DatabaseEngine
    {
        if ($this->engine === null) {
            throw new InvalidArgumentException('config/backup.php must declare an engine via ->engine(...).');
        }

        return $this->engine;
    }

    public function storeKeyValue(): string
    {
        return $this->storeKey;
    }

    public function keyPrefixValue(): string
    {
        return $this->keyPrefix;
    }

    public function environmentValue(): string
    {
        return $this->environment;
    }

    public function alertsConfig(): AlertsBuilder
    {
        return $this->alerts;
    }

    /**
     * The concrete, engine/environment-bound schedules (backup + retention + drill).
     *
     * @return list<BackupSchedule>
     */
    public function buildSchedules(): array
    {
        $engine = $this->resolvedEngine();
        $schedules = [];

        foreach ($this->schedules->entries() as $entry) {
            $schedules[] = new BackupSchedule(
                name: $entry['name'],
                engine: $engine,
                kind: $entry['kind'],
                environment: $this->environment,
                cron: $entry['cron'],
                type: $entry['type'],
            );
        }

        return $schedules;
    }

    /**
     * The effective retention policy — using the app's explicit hourly, or (when unset and a sub-daily
     * backup cadence is declared) a value derived from that cadence so sub-daily restore points survive.
     */
    public function buildRetentionPolicy(RetentionDerivation $derivation = new RetentionDerivation()): RetentionPolicy
    {
        $derivedHourly = 0;

        if (!$this->retention->hourlyWasSetExplicitly()) {
            $backupCron = $this->schedules->firstBackupCron();
            if ($backupCron !== null) {
                $derivedHourly = $derivation->hourlyFor($backupCron);
            }
        }

        return $this->retention->build($derivedHourly);
    }
}
