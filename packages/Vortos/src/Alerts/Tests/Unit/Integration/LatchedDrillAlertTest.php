<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Dedupe\AlertState;
use Vortos\Alerts\Dedupe\AlertStateStatus;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\Dedupe\Fingerprint;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Integration\Backup\BackupEventAlertSink;
use Vortos\Alerts\Runtime\StaleAlertResolver;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;

/**
 * A failed restore drill stays open until a drill of the same path passes. From 19 to 27 Nov 2026 the
 * logical path failed every day and its alert closed itself an hour later each time, while the
 * pgBackRest drill kept passing.
 */
final class LatchedDrillAlertTest extends TestCase
{
    private const T0 = '2026-11-27T04:00:00+00:00';

    public function test_silence_never_closes_a_drill_failure(): void
    {
        $store = new InMemoryAlertStateStore();
        $fp    = $this->failFingerprint('logical_full');
        $store->save(AlertState::firstSeen($fp, new DateTimeImmutable(self::T0), BackupEvent::TYPE_DRILL_FAILED));

        $closed = (new StaleAlertResolver($store, 3_600, null, BackupEventAlertSink::LATCHED_RULE_IDS))
            ->resolveStale(new DateTimeImmutable('2026-11-27T23:00:00+00:00'));

        self::assertSame(0, $closed);
        self::assertSame(AlertStateStatus::Open, $store->get($fp)?->status);
    }

    public function test_a_pass_on_the_same_path_closes_it_and_a_pass_on_another_path_does_not(): void
    {
        $store = new InMemoryAlertStateStore();
        $sink  = new BackupEventAlertSink($this->dispatcher(), states: $store);

        $logical = $this->failFingerprint('logical_full');
        $store->save(AlertState::firstSeen($logical, new DateTimeImmutable(self::T0), BackupEvent::TYPE_DRILL_FAILED));

        $sink->emit(BackupEvent::drillSucceeded(DatabaseEngine::Postgres, 'production', 90_000, new DateTimeImmutable('2026-11-28T01:32:00+00:00'))
            ->withScope('pgbackrest'));
        self::assertSame(AlertStateStatus::Open, $store->get($logical)?->status, 'pgBackRest passing says nothing about the logical path');

        $sink->emit(BackupEvent::drillSucceeded(DatabaseEngine::Postgres, 'production', 9_000, new DateTimeImmutable('2026-11-28T04:00:10+00:00'))
            ->withScope('logical_full'));
        self::assertSame(AlertStateStatus::Resolved, $store->get($logical)?->status);
    }

    public function test_the_failure_alert_carries_the_scope_so_its_fingerprint_matches(): void
    {
        $dispatcher = $this->dispatcher();
        $sink       = new BackupEventAlertSink($dispatcher);

        $sink->emit(BackupEvent::drillFailed(DatabaseEngine::Postgres, 'production', 'orphans', new DateTimeImmutable(self::T0))
            ->withScope('logical_full'));

        self::assertCount(1, $dispatcher->dispatched);
        self::assertSame($this->failFingerprint('logical_full'), Fingerprint::of($dispatcher->dispatched[0]));
    }

    private function failFingerprint(string $scope): string
    {
        return Fingerprint::compute(BackupEvent::TYPE_DRILL_FAILED, 'production', null, [
            'engine' => DatabaseEngine::Postgres->value,
            'type'   => BackupEvent::TYPE_DRILL_FAILED,
            'scope'  => $scope,
        ]);
    }

    private function dispatcher(): object
    {
        return new class implements AlertDispatcherInterface {
            /** @var list<AlertEvent> */
            public array $dispatched = [];

            public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
            {
                $this->dispatched[] = $event;

                return new DispatchResult(DedupeDecision::New, []);
            }
        };
    }
}
