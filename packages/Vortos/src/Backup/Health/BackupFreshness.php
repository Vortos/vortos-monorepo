<?php

declare(strict_types=1);

namespace Vortos\Backup\Health;

use DateTimeImmutable;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * The freshness verdict for one engine + environment: how old the newest catalogued backup is, how
 * old it was allowed to get, and what that means.
 *
 * Derived entirely from the catalog, never from worker state — see {@see BackupFreshnessInspector}
 * for why that distinction is the whole point.
 */
final readonly class BackupFreshness
{
    public function __construct(
        public DatabaseEngine $engine,
        public string $environment,
        public BackupFreshnessStatus $status,
        public ?DateTimeImmutable $lastSuccessAt,
        public ?int $ageSeconds,
        public int $maxAgeSeconds,
        public ?string $lastBackupId = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status->isHealthy();
    }

    /** A one-line, alert-ready description of the verdict. */
    public function describe(): string
    {
        return match ($this->status) {
            BackupFreshnessStatus::Fresh => sprintf(
                'Last %s/%s backup is %s old (threshold %s).',
                $this->engine->value,
                $this->environment,
                $this->humanAge($this->ageSeconds ?? 0),
                $this->humanAge($this->maxAgeSeconds),
            ),
            BackupFreshnessStatus::Stale => sprintf(
                'No %s/%s backup for %s — threshold is %s. The backup cadence has stopped.',
                $this->engine->value,
                $this->environment,
                $this->humanAge($this->ageSeconds ?? 0),
                $this->humanAge($this->maxAgeSeconds),
            ),
            BackupFreshnessStatus::NeverRun => sprintf(
                'No %s/%s backup has ever been catalogued.',
                $this->engine->value,
                $this->environment,
            ),
        };
    }

    /** @return array<string, scalar> */
    public function toDetail(): array
    {
        return [
            'engine' => $this->engine->value,
            'environment' => $this->environment,
            'status' => $this->status->value,
            'age_seconds' => $this->ageSeconds ?? -1,
            'max_age_seconds' => $this->maxAgeSeconds,
            'last_success_at' => $this->lastSuccessAt?->format(DATE_ATOM) ?? '',
        ];
    }

    private function humanAge(int $seconds): string
    {
        if ($seconds < 3600) {
            return sprintf('%dm', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        return sprintf('%dd%02dh', intdiv($seconds, 86400), intdiv($seconds % 86400, 3600));
    }
}
