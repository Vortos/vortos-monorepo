<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\Dedupe\AlertInhibitionSet;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\InhibitionRule;
use Vortos\Alerts\Dedupe\Inhibitor;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\RateLimit\OutboundRateLimitConfig;
use Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Severity;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Root-cause suppression: while a source rule is firing, its declared dependents are not paged.
 *
 * This was the whole point of the Inhibitor/InhibitionRule classes, which existed but were wired to
 * nothing in the dispatcher — a built-but-unattached feature. These tests hold the attachment: a
 * dependent alert is real (its state is still recorded open) but redundant (the operator is already
 * paged for the cause), so only the outbound page is withheld.
 */
final class AlertDispatcherInhibitionTest extends TestCase
{
    private const NOW = '2026-09-03T00:00:00Z';

    public function test_a_dependent_is_suppressed_while_its_source_is_firing(): void
    {
        $store   = new InMemoryAlertStateStore();
        $notifier = new SpyNotifier();
        $dispatcher = $this->dispatcher($store, $notifier, [
            new InhibitionRule(sourceRuleId: 'backup-worker-fatal', suppressedRuleId: 'backup-stale', windowSeconds: 600),
        ]);

        // The root cause fires first and pages normally.
        $dispatcher->dispatch($this->event('backup-worker-fatal'));
        $notifier->calls = 0; // ignore the source's own delivery

        // The dependent fires while the source is active: real, but redundant.
        $result = $dispatcher->dispatch($this->event('backup-stale'));

        self::assertSame('backup-worker-fatal', $result->suppressedBy, 'the dependent must name the source that suppressed it');
        self::assertSame([], $result->results, 'a suppressed alert is not delivered');
        self::assertSame(0, $notifier->calls, 'no notifier is invoked for a suppressed alert');
        self::assertNotNull($store->get(\Vortos\Alerts\Dedupe\Fingerprint::of($this->event('backup-stale'))), 'its state is still recorded open');
    }

    public function test_the_dependent_pages_normally_when_the_source_is_not_firing(): void
    {
        $store   = new InMemoryAlertStateStore();
        $notifier = new SpyNotifier();
        $dispatcher = $this->dispatcher($store, $notifier, [
            new InhibitionRule('backup-worker-fatal', 'backup-stale', 600),
        ]);

        // No source in the store, so nothing inhibits it.
        $result = $dispatcher->dispatch($this->event('backup-stale'));

        self::assertNull($result->suppressedBy);
        self::assertNotSame([], $result->results, 'an un-inhibited alert routes normally');
        self::assertGreaterThan(0, $notifier->calls);
    }

    public function test_an_expired_source_no_longer_suppresses(): void
    {
        $store   = new InMemoryAlertStateStore();
        $notifier = new SpyNotifier();
        $dispatcher = $this->dispatcher($store, $notifier, [
            new InhibitionRule('backup-worker-fatal', 'backup-stale', 600),
        ]);

        // Source last fired 20 minutes ago; the window is 10.
        $dispatcher->dispatch($this->event('backup-worker-fatal', at: '2026-09-02T23:40:00Z'));
        $notifier->calls = 0;

        $result = $dispatcher->dispatch($this->event('backup-stale', at: self::NOW));

        self::assertNull($result->suppressedBy, 'a source older than the window does not suppress');
        self::assertGreaterThan(0, $notifier->calls);
    }

    public function test_an_unrelated_active_alert_does_not_suppress(): void
    {
        $store   = new InMemoryAlertStateStore();
        $notifier = new SpyNotifier();
        $dispatcher = $this->dispatcher($store, $notifier, [
            new InhibitionRule('backup-worker-fatal', 'backup-stale', 600),
        ]);

        // A different rule is firing; it inhibits nothing here.
        $dispatcher->dispatch($this->event('object-store-unreachable'));
        $notifier->calls = 0;

        $result = $dispatcher->dispatch($this->event('backup-stale'));

        self::assertNull($result->suppressedBy);
        self::assertGreaterThan(0, $notifier->calls);
    }

    /** @param list<InhibitionRule> $inhibitions */
    private function dispatcher(InMemoryAlertStateStore $store, SpyNotifier $notifier, array $inhibitions): AlertDispatcher
    {
        $container = new class ($notifier) implements ContainerInterface {
            public function __construct(private readonly NotifierInterface $notifier) {}
            public function get(string $id): mixed { return $this->notifier; }
            public function has(string $id): bool { return true; }
        };

        return new AlertDispatcher(
            new Dedupe(),
            $store,
            new DedupeWindow(300),
            new Router(RoutingMatrix::default(), new ChannelRegistry([
                new ChannelDefinition('eng-chat', 'fake'),
                new ChannelDefinition('oncall-page', 'fake'),
            ])),
            new NotifierRegistry($container),
            new SlidingWindowOutboundRateLimiter(new OutboundRateLimitConfig(0, 0)),
            null,
            new Inhibitor(),
            new AlertInhibitionSet($inhibitions),
        );
    }

    private function event(string $ruleId, string $at = self::NOW): AlertEvent
    {
        return new AlertEvent(
            ruleId: $ruleId,
            severity: Severity::Critical,
            title: $ruleId,
            summary: 'x',
            source: AlertSource::Health,
            env: 'production',
            tenantId: null,
            labels: [],
            annotations: [],
            links: [],
            occurredAt: new DateTimeImmutable($at),
        );
    }
}

final class SpyNotifier implements NotifierInterface
{
    public int $calls = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function notify(NotifierMessage $message): NotificationResult
    {
        $this->calls++;

        return NotificationResult::delivered('fake');
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }
}
