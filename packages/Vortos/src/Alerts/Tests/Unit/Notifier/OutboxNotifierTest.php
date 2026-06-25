<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Notifier;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Notifier\NotificationOutcome;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Notifier\OutboxNotifier;
use Vortos\Alerts\Severity;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class OutboxNotifierTest extends TestCase
{
    private function spool(): BoundedSpool
    {
        return new BoundedSpool(sys_get_temp_dir() . '/vortos-alerts-test-' . bin2hex(random_bytes(8)) . '/outbox.spool', 1024 * 1024);
    }

    private function message(string $key = 'idem-1'): NotifierMessage
    {
        return new NotifierMessage($key, Severity::Critical, 'title', 'body', [], []);
    }

    public function test_notify_enqueues_and_returns_delivered(): void
    {
        $inner = new class implements NotifierInterface {
            public function name(): string
            {
                return 'fake';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                return NotificationResult::delivered('fake');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $outbox = new OutboxNotifier($inner, $this->spool());
        $result = $outbox->notify($this->message());

        self::assertSame(NotificationOutcome::Delivered, $result->outcome);
    }

    public function test_drain_delivers_through_inner_driver(): void
    {
        $delivered = [];
        $inner = new class($delivered) implements NotifierInterface {
            public array $received = [];

            public function __construct(private array &$delivered)
            {
            }

            public function name(): string
            {
                return 'fake';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                $this->received[] = $message->idempotencyKey;

                return NotificationResult::delivered('fake');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $outbox = new OutboxNotifier($inner, $this->spool());
        $outbox->notify($this->message('a'));
        $outbox->notify($this->message('b'));

        $results = $outbox->drain();

        self::assertCount(2, $results);
        self::assertSame(['a', 'b'], $inner->received);
    }

    public function test_failed_drain_respools_undelivered_remainder_in_order(): void
    {
        $inner = new class implements NotifierInterface {
            public array $failedOnce = [];

            public function name(): string
            {
                return 'fake';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                $key = $message->idempotencyKey;
                // 'b' fails its first attempt then recovers on retry; everything else succeeds.
                if ($key === 'b' && !isset($this->failedOnce[$key])) {
                    $this->failedOnce[$key] = true;

                    return NotificationResult::failed('fake', 'boom');
                }

                return NotificationResult::delivered('fake');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $outbox = new OutboxNotifier($inner, $this->spool());
        $outbox->notify($this->message('a'));
        $outbox->notify($this->message('b'));
        $outbox->notify($this->message('c'));

        $firstDrain = $outbox->drain();
        self::assertCount(2, $firstDrain); // 'a' delivered, 'b' attempted-and-failed, stops there

        // 'b' and 'c' must still be in the spool, in order.
        $secondDrain = $outbox->drain();
        self::assertCount(2, $secondDrain);
    }

    public function test_idempotency_key_prevents_double_enqueue(): void
    {
        $inner = new class implements NotifierInterface {
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
        };

        $outbox = new OutboxNotifier($inner, $this->spool());
        $first = $outbox->notify($this->message('same-key'));
        $second = $outbox->notify($this->message('same-key'));

        self::assertSame(NotificationOutcome::Delivered, $first->outcome);
        self::assertSame(NotificationOutcome::Deduped, $second->outcome);

        $outbox->drain();
        self::assertSame(1, $inner->calls, 'a retried idempotency key must never double-page');
    }
}
