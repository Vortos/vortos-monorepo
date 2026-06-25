<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Deploy;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Integration\Deploy\DeployAuditAlertSink;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Severity;
use Vortos\Deploy\Domain\Event\DeployFailed;
use Vortos\Deploy\Domain\Event\DeployRefused;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class DeployAuditAlertSinkTest extends TestCase
{
    private function fakeNotifier(): NotifierInterface
    {
        return new class implements NotifierInterface {
            public array $captured = [];

            public function name(): string
            {
                return 'fake';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                $this->captured[] = $message;

                return NotificationResult::delivered('fake');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };
    }

    private function dispatcherFor(NotifierInterface $notifier): AlertDispatcher
    {
        $container = new class($notifier) implements ContainerInterface {
            public function __construct(private NotifierInterface $notifier)
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

        $channels = new ChannelRegistry([
            new ChannelDefinition('eng-chat', 'fake'),
            new ChannelDefinition('oncall-page', 'fake'),
        ]);

        return new AlertDispatcher(
            new Dedupe(),
            new InMemoryAlertStateStore(),
            new DedupeWindow(300),
            new Router(RoutingMatrix::default(), $channels),
            new NotifierRegistry($container),
            new \Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter(new \Vortos\Alerts\RateLimit\OutboundRateLimitConfig(0, 0)),
        );
    }

    private function envelope(object $payload): EventEnvelope
    {
        return new EventEnvelope(
            eventId: 'evt-1',
            aggregateId: 'agg-1',
            aggregateType: 'Deploy',
            aggregateVersion: 1,
            payloadType: $payload::class,
            schemaVersion: 1,
            occurredAt: new DateTimeImmutable(),
            payload: $payload,
            metadata: Metadata::empty(),
        );
    }

    public function test_deploy_failed_produces_critical_alert(): void
    {
        $notifier = $this->fakeNotifier();
        $sink = new DeployAuditAlertSink($this->dispatcherFor($notifier));

        $event = new DeployFailed('prod', 'actor-1', 'oidc', 'build-1', 'sha1', 'sha256:abc', 'fp-1', 'reason', 'RuntimeException', 'boom');
        $sink->handle($this->envelope($event));

        self::assertNotEmpty($notifier->captured);
        self::assertSame(Severity::Critical, $notifier->captured[0]->severity);
    }

    public function test_deploy_refused_produces_warning_alert(): void
    {
        $notifier = $this->fakeNotifier();
        $sink = new DeployAuditAlertSink($this->dispatcherFor($notifier));

        $event = new DeployRefused('prod', 'actor-1', 'oidc', 'build-1', 'sha1', 'sha256:abc', 'fp-1', 'schema incompatible', ['check-1']);
        $sink->handle($this->envelope($event));

        self::assertNotEmpty($notifier->captured);
        self::assertSame(Severity::Warning, $notifier->captured[0]->severity);
    }

    public function test_handle_never_throws_for_unrelated_event(): void
    {
        $notifier = $this->fakeNotifier();
        $sink = new DeployAuditAlertSink($this->dispatcherFor($notifier));

        $sink->handle($this->envelope(new \stdClass()));

        self::assertEmpty($notifier->captured);
    }
}
