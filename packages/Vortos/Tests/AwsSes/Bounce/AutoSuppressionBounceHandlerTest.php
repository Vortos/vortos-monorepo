<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Bounce;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Bounce\AutoSuppressionBounceHandler;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\Webhook\BounceNotification;
use Vortos\AwsSes\Webhook\BounceType;

final class AutoSuppressionBounceHandlerTest extends TestCase
{
    private function makeNotification(BounceType $type): BounceNotification
    {
        return new BounceNotification(
            recipient:     new EmailAddress('user@example.com'),
            bounceType:    $type,
            bounceSubType: 'General',
            diagnosticCode: '550 User unknown',
            timestamp:     new \DateTimeImmutable(),
        );
    }

    private function makeList(): object
    {
        return new class implements SuppressionListInterface {
            public array $suppressed = [];
            public function isSuppressed(EmailAddress $address): bool { return false; }
            public function suppress(EmailAddress $address, SuppressionReason $reason): void
            {
                $this->suppressed[] = ['address' => $address->address(), 'reason' => $reason];
            }
            public function unsuppress(EmailAddress $address): void {}
            public function list(int $limit = 100, int $offset = 0): array { return []; }
        };
    }

    public function test_suppresses_on_hard_bounce(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionBounceHandler($list, new NullLogger());

        $handler->handle($this->makeNotification(BounceType::Permanent));

        $this->assertCount(1, $list->suppressed);
        $this->assertSame('user@example.com', $list->suppressed[0]['address']);
    }

    public function test_hard_bounce_suppressed_with_bounce_reason(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionBounceHandler($list, new NullLogger());

        $handler->handle($this->makeNotification(BounceType::Permanent));

        $this->assertSame(SuppressionReason::Bounce, $list->suppressed[0]['reason']);
    }

    public function test_does_not_suppress_on_soft_bounce(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionBounceHandler($list, new NullLogger());

        $handler->handle($this->makeNotification(BounceType::Transient));

        $this->assertCount(0, $list->suppressed);
    }

    public function test_does_not_suppress_on_undetermined_bounce(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionBounceHandler($list, new NullLogger());

        $handler->handle($this->makeNotification(BounceType::Undetermined));

        $this->assertCount(0, $list->suppressed);
    }
}
