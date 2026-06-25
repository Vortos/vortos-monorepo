<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Notifier;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Notifier\FallbackNotifier;
use Vortos\Alerts\Notifier\NotificationOutcome;
use Vortos\Alerts\Notifier\NotificationResult;
use Vortos\Alerts\Notifier\NotifierInterface;
use Vortos\Alerts\Notifier\NotifierMessage;
use Vortos\Alerts\Severity;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class FallbackNotifierTest extends TestCase
{
    private function notifier(string $key, NotificationResult $result): NotifierInterface
    {
        return new class($key, $result) implements NotifierInterface {
            public function __construct(private string $key, private NotificationResult $result)
            {
            }

            public function name(): string
            {
                return $this->key;
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                return $this->result;
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };
    }

    private function message(): NotifierMessage
    {
        return new NotifierMessage('idem', Severity::Critical, 'title', 'body', [], []);
    }

    public function test_walks_to_next_channel_on_failure(): void
    {
        $fallback = new FallbackNotifier([
            $this->notifier('primary', NotificationResult::failed('primary', 'down')),
            $this->notifier('secondary', NotificationResult::delivered('secondary')),
        ]);

        $result = $fallback->notify($this->message());

        self::assertSame(NotificationOutcome::Delivered, $result->outcome);
        self::assertSame('secondary', $result->channelKey);
    }

    public function test_primary_success_short_circuits(): void
    {
        $secondaryCalled = false;
        $secondary = new class($secondaryCalled) implements NotifierInterface {
            public function __construct(private bool &$called)
            {
            }

            public function name(): string
            {
                return 'secondary';
            }

            public function notify(NotifierMessage $message): NotificationResult
            {
                $this->called = true;

                return NotificationResult::delivered('secondary');
            }

            public function capabilities(): CapabilityDescriptor
            {
                return CapabilityDescriptor::create([]);
            }
        };

        $fallback = new FallbackNotifier([$this->notifier('primary', NotificationResult::delivered('primary')), $secondary]);
        $fallback->notify($this->message());

        self::assertFalse($secondaryCalled);
    }

    public function test_total_failure_is_loud_not_silent(): void
    {
        $fallback = new FallbackNotifier([
            $this->notifier('primary', NotificationResult::failed('primary', 'down')),
            $this->notifier('secondary', NotificationResult::failed('secondary', 'down too')),
        ]);

        $result = $fallback->notify($this->message());

        self::assertSame(NotificationOutcome::Failed, $result->outcome);
        self::assertStringContainsString('primary', (string) $result->reason);
        self::assertStringContainsString('secondary', (string) $result->reason);
    }

    public function test_rejects_empty_channel_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FallbackNotifier([]);
    }
}
