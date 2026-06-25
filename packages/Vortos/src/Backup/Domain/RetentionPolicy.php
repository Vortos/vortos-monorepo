<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * A grandfather-father-son (GFS) retention policy with hard safety floors.
 *
 * Given a homogeneous set of restore-point artifacts (one engine + environment +
 * kind), {@see plan()} keeps the most recent backup in each of the N most recent
 * hourly / daily / weekly / monthly / yearly buckets, plus an absolute `maxAgeDays`
 * cap and a `minKeepFloor` of most-recent backups that are *always* retained.
 *
 * Two invariants make it impossible to "delete the only good copy":
 *  1. the most recent {@see $minKeepFloor} artifacts are always kept;
 *  2. the single most-recent artifact is never placed in `delete` — if a rule would
 *     select it, it is moved to `refused` with an explanation.
 *
 * The function is **pure**: same inputs (including `$now`) → same plan, no I/O.
 */
final readonly class RetentionPolicy
{
    public function __construct(
        public int $hourly = 0,
        public int $daily = 7,
        public int $weekly = 4,
        public int $monthly = 6,
        public int $yearly = 1,
        public ?int $maxAgeDays = null,
        public int $minKeepFloor = 1,
    ) {
        foreach (['hourly' => $hourly, 'daily' => $daily, 'weekly' => $weekly, 'monthly' => $monthly, 'yearly' => $yearly] as $name => $value) {
            if ($value < 0) {
                throw new InvalidArgumentException("Retention '{$name}' must be >= 0.");
            }
        }
        if ($maxAgeDays !== null && $maxAgeDays < 1) {
            throw new InvalidArgumentException('Retention maxAgeDays must be >= 1 or null.');
        }
        if ($minKeepFloor < 1) {
            throw new InvalidArgumentException('Retention minKeepFloor must be >= 1 (never risk deleting the only copy).');
        }
    }

    /**
     * @param list<BackupArtifact> $artifacts a homogeneous restore-point set
     */
    public function plan(array $artifacts, DateTimeImmutable $now): RetentionPlan
    {
        // Newest first — buckets are filled most-recent-wins, and the floor protects the head.
        usort($artifacts, static fn (BackupArtifact $a, BackupArtifact $b): int => $b->createdAt <=> $a->createdAt);

        $keepIds = [];

        // (1) Floor: always keep the most-recent N.
        foreach (array_slice($artifacts, 0, $this->minKeepFloor) as $a) {
            $keepIds[$a->id->value()] = true;
        }

        // (2) GFS slots: keep the most-recent artifact of each of the most-recent buckets.
        $this->fillBuckets($artifacts, 'Y-m-d-H', $this->hourly, $keepIds);
        $this->fillBuckets($artifacts, 'Y-m-d', $this->daily, $keepIds);
        $this->fillBuckets($artifacts, 'o-W', $this->weekly, $keepIds);     // ISO-8601 year-week
        $this->fillBuckets($artifacts, 'Y-m', $this->monthly, $keepIds);
        $this->fillBuckets($artifacts, 'Y', $this->yearly, $keepIds);

        $keep = [];
        $delete = [];
        $refused = [];

        $newestId = $artifacts === [] ? null : $artifacts[0]->id->value();

        foreach ($artifacts as $index => $artifact) {
            $id = $artifact->id->value();
            $keptBySlot = isset($keepIds[$id]);

            if ($keptBySlot) {
                $keep[] = $artifact;
                continue;
            }

            $tooOld = $this->maxAgeDays !== null && $this->ageDays($artifact, $now) > $this->maxAgeDays;

            // Within the maxAge window but not slot-selected → retain (don't prune recent
            // backups just because no GFS bucket claimed them). With no maxAge configured,
            // pure GFS pruning applies: anything not slot-kept is a delete candidate.
            $shouldKeep = $this->maxAgeDays !== null && !$tooOld;

            if ($shouldKeep) {
                $keep[] = $artifact;
                continue;
            }

            // Guard: never delete the single most-recent artifact, whatever the rules say.
            if ($id === $newestId || $index < $this->minKeepFloor) {
                $refused[] = ['artifact' => $artifact, 'reason' => 'floor_protected_most_recent'];
                $keep[] = $artifact;
                continue;
            }

            $delete[] = $artifact;
        }

        return new RetentionPlan($keep, $delete, $refused);
    }

    /**
     * @param list<BackupArtifact>      $artifacts newest-first
     * @param array<string, bool>       $keepIds   accumulator (by-ref)
     */
    private function fillBuckets(array $artifacts, string $format, int $count, array &$keepIds): void
    {
        if ($count <= 0) {
            return;
        }

        $seenBuckets = [];
        $utc = new DateTimeZone('UTC');

        foreach ($artifacts as $artifact) {
            $bucket = $artifact->createdAt->setTimezone($utc)->format($format);
            if (isset($seenBuckets[$bucket])) {
                continue; // already kept the most-recent of this bucket
            }
            if (count($seenBuckets) >= $count) {
                break; // filled the N most-recent buckets for this period
            }
            $seenBuckets[$bucket] = true;
            $keepIds[$artifact->id->value()] = true;
        }
    }

    private function ageDays(BackupArtifact $artifact, DateTimeImmutable $now): float
    {
        return ($now->getTimestamp() - $artifact->createdAt->getTimestamp()) / 86400;
    }
}
