<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Backup;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\AlertDispatcher;
use Vortos\Alerts\Dedupe\Dedupe;
use Vortos\Alerts\Dedupe\DedupeWindow;
use Vortos\Alerts\Dedupe\InMemoryAlertStateStore;
use Vortos\Alerts\Integration\Backup\BackupEventAlertSink;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\NotifierRegistry;
use Vortos\Alerts\Routing\ChannelDefinition;
use Vortos\Alerts\Routing\ChannelRegistry;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Routing\Router;
use Vortos\Alerts\Severity;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class BackupEventAlertSinkTest extends TestCase
{
    public function test_backup_failed_produces_critical_alert_through_routing_to_fake_notifier(): void
    {
        $captured = [];
        $fakeNotifier = new class($captured) implements NotifierInterface {
            public array $captured = [];

            public function __construct(array &$captured)
            {
            }

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

        $container = new class($fakeNotifier) implements ContainerInterface {
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
        $dispatcher = new AlertDispatcher(
            new Dedupe(),
            new InMemoryAlertStateStore(),
            new DedupeWindow(300),
            new Router(RoutingMatrix::default(), $channels),
            new NotifierRegistry($container),
            new \Vortos\Alerts\RateLimit\SlidingWindowOutboundRateLimiter(new \Vortos\Alerts\RateLimit\OutboundRateLimitConfig(0, 0)),
        );

        $sink = new BackupEventAlertSink($dispatcher);
        $sink->emit(BackupEvent::failed(DatabaseEngine::Postgres, 'prod', 'disk full', new DateTimeImmutable()));

        self::assertCount(2, $fakeNotifier->captured, 'critical severity routes to page + chat mirror');
        self::assertSame(Severity::Critical, $fakeNotifier->captured[0]->severity);
    }

    public function test_emit_never_throws_even_if_dispatcher_explodes(): void
    {
        $dispatcher = new class implements \Vortos\Alerts\AlertDispatcherInterface {
            public function dispatch(\Vortos\Alerts\Event\AlertEvent $event, ?array $routingOverride = null): \Vortos\Alerts\DispatchResult
            {
                throw new \RuntimeException('boom');
            }
        };

        $sink = new BackupEventAlertSink($dispatcher);
        $sink->emit(BackupEvent::failed(DatabaseEngine::Postgres, 'prod', 'disk full', new DateTimeImmutable()));
        $this->addToAssertionCount(1); // never throws — a broken alerter must never mask a backup
    }
}
