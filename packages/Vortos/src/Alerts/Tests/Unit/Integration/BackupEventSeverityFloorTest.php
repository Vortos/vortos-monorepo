<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Integration\Backup\BackupEventAlertSink;
use Vortos\Alerts\Severity;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSeverity;

/**
 * Failures reach people; successes reach metrics.
 *
 * A backup lifecycle emits Info on every success — every backup, every retention pass, every
 * restore drill. Routing those to a chat channel is alert fatigue that buys nothing: "I stopped
 * seeing the success message" is not something anyone reliably notices, and the case it pretends to
 * cover is already covered mechanically by the freshness dead-man, which fires when a backup stops
 * ARRIVING rather than when one fails.
 */
final class BackupEventSeverityFloorTest extends TestCase
{
    /** @return array{0: BackupEventAlertSink, 1: object} */
    private function sink(?Severity $floor = null): array
    {
        $dispatcher = new class implements AlertDispatcherInterface {
            /** @var list<AlertEvent> */
            public array $dispatched = [];

            public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
            {
                $this->dispatched[] = $event;

                // A real DispatchResult, not a bare `new`. The sink swallows every Throwable so a
                // broken alerter can never fail a backup — which means a double that throws on
                // construction would be silently absorbed and these tests would pass without ever
                // proving the return path works.
                return new DispatchResult(DedupeDecision::New, []);
            }
        };

        return [
            $floor === null
                ? new BackupEventAlertSink($dispatcher)
                : new BackupEventAlertSink($dispatcher, $floor),
            $dispatcher,
        ];
    }

    private function event(string $type, BackupEventSeverity $severity): BackupEvent
    {
        return new BackupEvent(
            $type,
            $severity,
            DatabaseEngine::Postgres,
            'production',
            'message',
            new DateTimeImmutable('2026-09-04 05:00:00'),
        );
    }

    public function testASuccessfulDrillDoesNotReachTheChannelByDefault(): void
    {
        [$sink, $dispatcher] = $this->sink();

        $sink->emit($this->event(BackupEvent::TYPE_DRILL_SUCCEEDED, BackupEventSeverity::Info));
        $sink->emit($this->event(BackupEvent::TYPE_SUCCEEDED, BackupEventSeverity::Info));
        $sink->emit($this->event(BackupEvent::TYPE_RETENTION_APPLIED, BackupEventSeverity::Info));

        self::assertSame([], $dispatcher->dispatched);
    }

    /**
     * The half that must never be suppressed. A failed point-in-time drill is the only signal that
     * the WAL chain cannot be replayed.
     */
    public function testFailuresStillReachTheChannel(): void
    {
        [$sink, $dispatcher] = $this->sink();

        $sink->emit($this->event(BackupEvent::TYPE_DRILL_FAILED, BackupEventSeverity::Critical));
        $sink->emit($this->event(BackupEvent::TYPE_FAILED, BackupEventSeverity::Critical));
        $sink->emit($this->event(BackupEvent::TYPE_STALE, BackupEventSeverity::Critical));

        self::assertCount(3, $dispatcher->dispatched);
        foreach ($dispatcher->dispatched as $alert) {
            self::assertSame(Severity::Critical, $alert->severity);
            self::assertTrue($alert->severity->isPaging());
        }
    }

    public function testWarningsAreNotSuppressed(): void
    {
        [$sink, $dispatcher] = $this->sink();

        $sink->emit($this->event('backup.something', BackupEventSeverity::Warning));

        self::assertCount(1, $dispatcher->dispatched);
    }

    /** An installation with no metrics pipeline can opt back into the chatter. */
    public function testTheFloorIsOverridable(): void
    {
        [$sink, $dispatcher] = $this->sink(Severity::Info);

        $sink->emit($this->event(BackupEvent::TYPE_DRILL_SUCCEEDED, BackupEventSeverity::Info));

        self::assertCount(1, $dispatcher->dispatched);
    }
}
