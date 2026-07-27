<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Integration\Audit\AlertAuditEntry;
use Vortos\Alerts\Integration\Audit\AlertAuditRecorderInterface;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\RateLimit\OutboundRateLimitConfig;
use Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\RoutedDelivery;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Severity;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The dispatcher must WRITE the alert audit ledger.
 *
 * AlertAuditRecorder was registered in the DI extension and referenced by nothing, so
 * `alerts_audit_log` stayed empty however many alerts fired — a ledger that existed and recorded
 * nothing. Delivery was unaffected, which is exactly why it went unnoticed: the gap only surfaces
 * later, when someone asks whether an alert fired and there is no way to answer.
 */
final class AlertDispatcherAuditTest extends TestCase
{
    public function test_a_dispatched_alert_is_recorded_in_the_audit_ledger(): void
    {
        $recorder = new SpyAuditRecorder();

        $this->makeDispatcher($recorder)->dispatch($this->event());

        // One entry PER DELIVERY, not per alert: a Critical event routes to both eng-chat and
        // oncall-page, and "which channels were actually paged" is the point of the record.
        self::assertCount(2, $recorder->calls, 'dispatching an alert must write an audit entry per delivery');
    }

    public function test_a_failed_delivery_is_still_recorded(): void
    {
        // "We tried to page you and the webhook rejected it" is the entry an incident review most
        // needs — and the one a success-only log would omit.
        $recorder = new SpyAuditRecorder();

        $this->makeDispatcher($recorder, notifierFails: true)->dispatch($this->event());

        self::assertCount(2, $recorder->calls);
        self::assertSame(['failed', 'failed'], $recorder->calls);
    }

    public function test_a_broken_ledger_never_suppresses_the_alert(): void
    {
        // Auditing is bookkeeping; the page is the operational event. A ledger write that throws
        // must not cost the alert.
        $result = $this->makeDispatcher(new ThrowingAuditRecorder())->dispatch($this->event());

        self::assertNotSame([], $result->results, 'delivery must survive an audit failure');
    }

    public function test_it_works_without_a_recorder_at_all(): void
    {
        $result = $this->makeDispatcher(null)->dispatch($this->event());

        self::assertNotSame([], $result->results);
    }

    private function event(): AlertEvent
    {
        return new AlertEvent(
            ruleId: 'test-rule',
            severity: Severity::Critical,
            title: 'Test alert',
            summary: 'something is wrong',
            source: AlertSource::Health,
            env: 'production',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable('2026-07-27T00:00:00Z'),
        );
    }

    private function makeDispatcher(?AlertAuditRecorderInterface $recorder, bool $notifierFails = false): AlertDispatcher
    {
        $notifier = new class ($notifierFails) implements NotifierInterface {
            public function __construct(private readonly bool $fails)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                return $this->fails
                    ? NotificationResult::failed('fake', 'webhook rejected')
                    : NotificationResult::delivered('fake');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $container = new class ($notifier) implements ContainerInterface {
            public function __construct(private readonly NotifierInterface $notifier)
            {
            }

            public function get(string $id): mixed
            {
                return $this->notifier;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        return new AlertDispatcher(
            new Dedupe(),
            new InMemoryAlertStateStore(),
            new DedupeWindow(300),
            new Router(RoutingMatrix::default(), new ChannelRegistry([
                new ChannelDefinition('eng-chat', 'fake'),
                new ChannelDefinition('oncall-page', 'fake'),
            ])),
            new NotifierRegistry($container),
            new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(0, 0)),
            $recorder,
        );
    }
}

final class SpyAuditRecorder implements AlertAuditRecorderInterface
{
    public function isOperational(): bool
    {
        return true;
    }

    /** @var list<string> */
    public array $calls = [];

    public function recordNotification(
        AlertEvent $event,
        RoutedDelivery $delivery,
        NotificationResult $result,
        DateTimeImmutable $now,
    ): AlertAuditEntry {
        $this->calls[] = $result->outcome->value;

        // The dispatcher ignores the return value; this satisfies the contract without needing a
        // real hash chain.
        return new AlertAuditEntry(
            entryId: 'entry-' . \count($this->calls),
            sequence: \count($this->calls),
            env: $event->env,
            eventType: 'notification',
            fingerprint: 'fp',
            actorId: 'system',
            occurredAt: $now->format(DATE_ATOM),
            data: [],
            prevHash: '',
            contentHash: '',
            signature: '',
        );
    }
}

final class ThrowingAuditRecorder implements AlertAuditRecorderInterface
{
    public function isOperational(): bool
    {
        return true;
    }

    public function recordNotification(
        AlertEvent $event,
        RoutedDelivery $delivery,
        NotificationResult $result,
        DateTimeImmutable $now,
    ): AlertAuditEntry {
        throw new \RuntimeException('ledger unavailable');
    }
}
