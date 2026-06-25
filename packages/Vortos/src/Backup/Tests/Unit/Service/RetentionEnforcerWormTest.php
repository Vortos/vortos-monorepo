<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Service\RetentionEnforcer;
use Vortos\Backup\Tests\Support\ArtifactFactory;
use Vortos\Backup\Tests\Support\CollectingEventSink;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Backup\Tests\Support\InMemoryCatalogRepository;

final class RetentionEnforcerWormTest extends TestCase
{
    public function test_locked_artifact_excluded_from_delete_plan(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events = new CollectingEventSink();
        $now = new DateTimeImmutable('2026-06-24');
        $clock = new FixedClock($now);

        // Create an artifact from 5 days ago (within 30-day lock)
        $recent = ArtifactFactory::at('2026-06-20 02:00:00');
        // Create an artifact from 60 days ago (outside 30-day lock)
        $old = ArtifactFactory::at('2026-04-25 02:00:00');

        $catalog->record($recent);
        $catalog->record($old);

        $lockPolicy = new ObjectLockPolicy('compliance', 30);
        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock, $lockPolicy);

        // Use a policy that would delete the old one
        $policy = new RetentionPolicy(hourly: 0, daily: 1, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 1);
        $plan = $enforcer->plan(DatabaseEngine::Postgres, 'prod', $policy);

        // The recent artifact should be kept, the old one can be deleted (outside lock window)
        $deletedKeys = array_map(fn ($a) => $a->storeKey, $plan->delete);
        $keptKeys = array_map(fn ($a) => $a->storeKey, $plan->keep);

        $this->assertContains($recent->storeKey, $keptKeys);
        // Old artifact is outside the 30-day lock window, so eligible for deletion
    }

    public function test_legal_hold_prevents_all_deletion(): void
    {
        $catalog = new InMemoryCatalogRepository();
        $events = new CollectingEventSink();
        $clock = new FixedClock(new DateTimeImmutable('2026-06-24'));

        // Old artifact from a year ago, but legal hold is on
        $old = ArtifactFactory::at('2025-06-20 02:00:00');
        $newer = ArtifactFactory::at('2026-06-20 02:00:00');
        $catalog->record($old);
        $catalog->record($newer);

        $lockPolicy = new ObjectLockPolicy('compliance', 30, legalHold: true);
        $enforcer = new RetentionEnforcer($catalog, $catalog, $events, $clock, $lockPolicy);

        $policy = new RetentionPolicy(hourly: 0, daily: 1, weekly: 0, monthly: 0, yearly: 0, minKeepFloor: 1);
        $plan = $enforcer->plan(DatabaseEngine::Postgres, 'prod', $policy);

        // With legal hold, nothing should be in the delete set
        $this->assertSame([], $plan->delete, 'Legal hold must prevent all deletions.');
    }

    public function test_object_lock_policy_rejects_invalid_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectLockPolicy('invalid', 30);
    }

    public function test_object_lock_policy_rejects_zero_days(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectLockPolicy('compliance', 0);
    }

    public function test_within_retention_window(): void
    {
        $policy = new ObjectLockPolicy('compliance', 30);
        $created = new DateTimeImmutable('2026-06-01');
        $within = new DateTimeImmutable('2026-06-15');
        $outside = new DateTimeImmutable('2026-07-15');

        $this->assertTrue($policy->isWithinRetention($created, $within));
        $this->assertFalse($policy->isWithinRetention($created, $outside));
    }
}
