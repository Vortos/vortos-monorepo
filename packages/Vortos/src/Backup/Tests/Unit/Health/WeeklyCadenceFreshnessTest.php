<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Health\BackupFreshnessInspector;
use Vortos\Backup\Schedule\CadenceInterval;

/**
 * A weekly backup must not be called stale two days after it ran.
 *
 * The cadence walk used a 48-hour window, which covers every sub-daily schedule and none of the
 * others. A weekly cron fires zero times inside it, so it measured as unmeasurable and inherited the
 * 48-hour fallback — meaning a backup that runs every 168 hours was older than its own staleness
 * threshold for six days out of every seven. The dead-man alarm would have paged every week, at
 * Critical, on a completely healthy system.
 *
 * That is worse than not alerting at all: a weekly false page is one people learn to dismiss, and
 * this is the alarm whose entire job is noticing that the backup worker has quietly died.
 */
final class WeeklyCadenceFreshnessTest extends TestCase
{
    private const WEEKLY = '0 2 * * 0';
    private const HOUR = 3600;

    public function testAWeeklyCronIsMeasurable(): void
    {
        self::assertSame(
            168 * self::HOUR,
            (new CadenceInterval())->shortestIntervalSeconds(self::WEEKLY),
            'a weekly cadence must be measured, not fall back to an arbitrary ceiling',
        );
    }

    public function testAWeeklyBackupIsNotStaleAfterTwoDays(): void
    {
        $threshold = $this->inspector()->thresholdFor(self::WEEKLY);

        self::assertGreaterThan(
            168 * self::HOUR,
            $threshold,
            'the threshold must exceed the cadence itself, or the alarm fires between every run',
        );
        // The declared policy: tolerate one missed run, page on two.
        self::assertSame((int) round(168 * self::HOUR * BackupFreshnessInspector::DEFAULT_TOLERANCE_FACTOR), $threshold);
    }

    /** Sub-daily cadences must be unaffected — they were the only ones that ever worked. */
    public function testSubDailyCadencesAreUnchanged(): void
    {
        $inspector = $this->inspector();

        self::assertSame(30 * self::HOUR, $inspector->thresholdFor('0 */12 * * *'));
        self::assertSame(60 * self::HOUR, $inspector->thresholdFor('0 3 * * *'));
    }

    private function inspector(): BackupFreshnessInspector
    {
        // Only thresholdFor() is exercised, and it depends on nothing but the cadence.
        return new BackupFreshnessInspector(
            catalog: new \Vortos\Backup\Tests\Support\InMemoryCatalogRepository(),
            schedules: new \Vortos\Backup\Schedule\BackupScheduleRegistry([]),
            clock: new \Vortos\Backup\Tests\Support\FixedClock(new \DateTimeImmutable('2026-09-04 08:00:00')),
        );
    }
}
