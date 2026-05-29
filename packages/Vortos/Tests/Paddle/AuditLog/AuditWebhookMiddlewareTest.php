<?php

declare(strict_types=1);

namespace Vortos\Tests\Paddle\AuditLog;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\Paddle\AuditLog\AuditWebhookMiddleware;
use Vortos\Paddle\AuditLog\PaddleAuditEntry;
use Vortos\Paddle\AuditLog\PaddleAuditLogWriterInterface;
use Vortos\Paddle\Webhook\Event\Subscription\SubscriptionCreatedEvent;
use Vortos\Paddle\Webhook\Event\PaddleWebhookEvent;
use Vortos\Paddle\Webhook\PaddleWebhookDispatcher;

final class AuditWebhookMiddlewareTest extends TestCase
{
    private function makeEvent(string $eventType = 'subscription.created', array $data = []): PaddleWebhookEvent
    {
        return new SubscriptionCreatedEvent(
            eventId:        'evt_audit_01',
            notificationId: 'ntf_01',
            eventType:      $eventType,
            occurredAt:     new \DateTimeImmutable('2024-06-01T12:00:00Z'),
            data:           $data,
        );
    }

    public function test_middleware_calls_inner_dispatcher(): void
    {
        $called     = false;
        $handler    = new class('subscription.created', $called) implements \Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface {
            public function __construct(private string $type, public bool &$called) {}
            public function handles(): string { return $this->type; }
            public function handle(PaddleWebhookEvent $e): void { $this->called = true; }
        };

        $dispatcher = new PaddleWebhookDispatcher([$handler], new NullLogger());
        $writer     = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->expects($this->once())->method('record');

        $middleware = new AuditWebhookMiddleware($dispatcher, $writer);
        $middleware->dispatch($this->makeEvent('subscription.created'));

        $this->assertTrue($called);
    }

    public function test_audit_entry_is_written_after_dispatch(): void
    {
        $dispatcher = new PaddleWebhookDispatcher([], new NullLogger());

        $captured = null;
        $writer   = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->expects($this->once())
               ->method('record')
               ->willReturnCallback(static function (PaddleAuditEntry $entry) use (&$captured): void {
                   $captured = $entry;
               });

        $middleware = new AuditWebhookMiddleware($dispatcher, $writer);
        $middleware->dispatch($this->makeEvent('subscription.created', ['id' => 'sub_abc']));

        $this->assertNotNull($captured);
        $this->assertSame('subscription.created', $captured->eventType);
        $this->assertSame('evt_audit_01', $captured->paddleEventId);
        $this->assertSame('subscription', $captured->entityType);
        $this->assertSame('sub_abc', $captured->entityId);
        $this->assertSame('webhook', $captured->actor);
    }

    public function test_entity_type_derived_from_event_type_prefix(): void
    {
        $dispatcher = new PaddleWebhookDispatcher([], new NullLogger());

        $captured = null;
        $writer   = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->method('record')
               ->willReturnCallback(static function (PaddleAuditEntry $e) use (&$captured): void {
                   $captured = $e;
               });

        $middleware = new AuditWebhookMiddleware($dispatcher, $writer);
        $middleware->dispatch($this->makeEvent('transaction.completed', ['id' => 'txn_001']));

        $this->assertSame('transaction', $captured->entityType);
        $this->assertSame('txn_001', $captured->entityId);
    }

    public function test_entity_id_falls_back_to_event_id_when_no_data_id(): void
    {
        $dispatcher = new PaddleWebhookDispatcher([], new NullLogger());

        $captured = null;
        $writer   = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->method('record')
               ->willReturnCallback(static function (PaddleAuditEntry $e) use (&$captured): void {
                   $captured = $e;
               });

        $middleware = new AuditWebhookMiddleware($dispatcher, $writer);
        $middleware->dispatch($this->makeEvent('subscription.updated', []));

        $this->assertSame('evt_audit_01', $captured->entityId);
    }

    public function test_audit_entry_written_even_when_handler_throws(): void
    {
        $handler = new class('subscription.created') implements \Vortos\Paddle\Webhook\PaddleWebhookHandlerInterface {
            public function __construct(private string $type) {}
            public function handles(): string { return $this->type; }
            public function handle(PaddleWebhookEvent $e): void { throw new \RuntimeException('Handler error'); }
        };

        $dispatcher = new PaddleWebhookDispatcher([$handler], new NullLogger());

        $writer = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->expects($this->once())->method('record');

        $middleware = new AuditWebhookMiddleware($dispatcher, $writer);
        $middleware->dispatch($this->makeEvent('subscription.created'));
    }

    public function test_occurred_at_matches_event(): void
    {
        $dispatcher = new PaddleWebhookDispatcher([], new NullLogger());

        $captured = null;
        $writer   = $this->createMock(PaddleAuditLogWriterInterface::class);
        $writer->method('record')
               ->willReturnCallback(static function (PaddleAuditEntry $e) use (&$captured): void {
                   $captured = $e;
               });

        $middleware  = new AuditWebhookMiddleware($dispatcher, $writer);
        $event       = $this->makeEvent('subscription.created');
        $middleware->dispatch($event);

        $this->assertEquals($event->occurredAt, $captured->occurredAt);
    }
}
