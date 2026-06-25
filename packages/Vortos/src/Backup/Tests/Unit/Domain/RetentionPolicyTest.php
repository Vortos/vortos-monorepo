<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Tests\Support\ArtifactFactory;

final class RetentionPolicyTest extends TestCase
{
    private function ids(array $artifacts): array
    {
        return array_map(static fn (BackupArtifact $a): string => $a->id->value(), $artifacts);
    }

    public function test_empty_set_is_a_noop(): void
    {
        $plan = (new RetentionPolicy())->plan([], new DateTimeImmutable('2026-06-23'));

        $this->assertSame([], $plan->keep);
        $this->assertSame([], $plan->delete);
        $this->assertTrue($plan->isNoop());
    }

    public function test_single_backup_is_always_kept(): void
    {
        $only = ArtifactFactory::at('2020-01-01 00:00:00');
        $plan = (new RetentionPolicy(daily: 7))->plan([$only], new DateTimeImmutable('2026-06-23'));

        $this->assertSame($this->ids([$only]), $this->ids($plan->keep));
        $this->assertSame([], $plan->delete);
    }

    public function test_keeps_one_per_daily_bucket_up_to_count(): void
    {
        // 5 backups on 5 distinct days; keep 3 daily → keep newest 3 days, delete 2 oldest.
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-22 02:00:00'),
            ArtifactFactory::at('2026-06-21 02:00:00'),
            ArtifactFactory::at('2026-06-20 02:00:00'),
            ArtifactFactory::at('2026-06-19 02:00:00'),
        ];

        $plan = (new RetentionPolicy(daily: 3, weekly: 0, monthly: 0, yearly: 0))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));

        $this->assertCount(3, $plan->keep);
        $this->assertCount(2, $plan->delete);
    }

    public function test_keeps_only_most_recent_within_same_day(): void
    {
        // Two backups same day → daily=1 keeps the newer; floor protects it anyway.
        $newer = ArtifactFactory::at('2026-06-23 06:00:00');
        $older = ArtifactFactory::at('2026-06-23 01:00:00');

        $plan = (new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0))
            ->plan([$older, $newer], new DateTimeImmutable('2026-06-23 12:00:00'));

        $this->assertSame($this->ids([$newer]), $this->ids($plan->keep));
        $this->assertSame($this->ids([$older]), $this->ids($plan->delete));
    }

    public function test_gfs_combines_periods(): void
    {
        // Daily across a week + one much older monthly anchor.
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-22 02:00:00'),
            ArtifactFactory::at('2026-06-21 02:00:00'),
            ArtifactFactory::at('2026-03-15 02:00:00'), // older — only survives via monthly/yearly
        ];

        $keepByDailyOnly = (new RetentionPolicy(daily: 2, weekly: 0, monthly: 0, yearly: 0))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));
        $this->assertCount(2, $keepByDailyOnly->keep);
        $this->assertCount(2, $keepByDailyOnly->delete);

        $keepWithMonthly = (new RetentionPolicy(daily: 2, weekly: 0, monthly: 2, yearly: 0))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));
        // monthly keeps the March anchor too.
        $this->assertCount(3, $keepWithMonthly->keep);
        $this->assertCount(1, $keepWithMonthly->delete);
    }

    public function test_max_age_retains_recent_unselected_backups(): void
    {
        // With maxAgeDays set, a backup within the window is kept even if no GFS slot claims it.
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-23 03:00:00'),
            ArtifactFactory::at('2026-06-22 02:00:00'),
        ];

        $plan = (new RetentionPolicy(hourly: 0, daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));

        $this->assertSame([], $plan->delete, 'Nothing within maxAge should be deleted.');
        $this->assertTrue($plan->isNoop());
    }

    public function test_max_age_deletes_old_unselected_backups(): void
    {
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),  // recent, kept by floor/daily
            ArtifactFactory::at('2020-01-01 02:00:00'),  // ancient, not slot-kept, beyond maxAge
        ];

        $plan = (new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0, maxAgeDays: 30))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));

        $this->assertCount(1, $plan->delete);
        $this->assertSame('2020-01-01 02:00:00', $plan->delete[0]->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_most_recent_is_never_deleted_even_under_aggressive_policy(): void
    {
        // Pure GFS with all-zero counts would prune everything; the floor + most-recent
        // guard must still protect the newest backup.
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-22 02:00:00'),
        ];

        $plan = (new RetentionPolicy(hourly: 0, daily: 0, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 1))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));

        $keptIds = $this->ids($plan->keep);
        $this->assertContains($artifacts[0]->id->value(), $keptIds, 'Newest must never be deleted.');
        $this->assertNotContains($artifacts[0]->id->value(), $this->ids($plan->delete));
    }

    public function test_floor_protects_n_most_recent(): void
    {
        $artifacts = [
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-22 02:00:00'),
            ArtifactFactory::at('2026-06-21 02:00:00'),
        ];

        $plan = (new RetentionPolicy(hourly: 0, daily: 0, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 2))
            ->plan($artifacts, new DateTimeImmutable('2026-06-23 12:00:00'));

        $this->assertGreaterThanOrEqual(2, count($plan->keep));
        $this->assertContains($artifacts[0]->id->value(), $this->ids($plan->keep));
        $this->assertContains($artifacts[1]->id->value(), $this->ids($plan->keep));
    }

    public function test_order_independent_input(): void
    {
        $a = ArtifactFactory::at('2026-06-23 02:00:00');
        $b = ArtifactFactory::at('2026-06-21 02:00:00');
        $c = ArtifactFactory::at('2026-06-19 02:00:00');

        $policy = new RetentionPolicy(daily: 2, weekly: 0, monthly: 0, yearly: 0);
        $now = new DateTimeImmutable('2026-06-23 12:00:00');

        $plan1 = $policy->plan([$a, $b, $c], $now);
        $plan2 = $policy->plan([$c, $a, $b], $now);

        $d1 = $this->ids($plan1->delete);
        $d2 = $this->ids($plan2->delete);
        sort($d1);
        sort($d2);
        $this->assertSame($d1, $d2);
    }

    public function test_rejects_invalid_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RetentionPolicy(minKeepFloor: 0);
    }

    public function test_serialize_shape(): void
    {
        $plan = (new RetentionPolicy(daily: 1, weekly: 0, monthly: 0, yearly: 0))->plan([
            ArtifactFactory::at('2026-06-23 02:00:00'),
            ArtifactFactory::at('2026-06-21 02:00:00'),
        ], new DateTimeImmutable('2026-06-23 12:00:00'));

        $serialized = $plan->serialize();
        $this->assertArrayHasKey('keep', $serialized);
        $this->assertArrayHasKey('delete', $serialized);
        $this->assertArrayHasKey('refused', $serialized);
    }
}
