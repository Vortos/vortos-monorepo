<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Bounce;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Vortos\AwsSes\Bounce\AutoSuppressionComplaintHandler;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;
use Vortos\AwsSes\Webhook\ComplaintNotification;

final class AutoSuppressionComplaintHandlerTest extends TestCase
{
    private function makeNotification(?string $feedbackType = 'abuse'): ComplaintNotification
    {
        return new ComplaintNotification(
            recipient:            new EmailAddress('spammer@example.com'),
            complaintFeedbackType: $feedbackType,
            timestamp:            new \DateTimeImmutable(),
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

    public function test_always_suppresses_complained_address(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionComplaintHandler($list, new NullLogger());

        $handler->handle($this->makeNotification());

        $this->assertCount(1, $list->suppressed);
        $this->assertSame('spammer@example.com', $list->suppressed[0]['address']);
    }

    public function test_suppresses_with_complaint_reason(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionComplaintHandler($list, new NullLogger());

        $handler->handle($this->makeNotification());

        $this->assertSame(SuppressionReason::Complaint, $list->suppressed[0]['reason']);
    }

    public function test_suppresses_even_when_feedback_type_is_null(): void
    {
        $list    = $this->makeList();
        $handler = new AutoSuppressionComplaintHandler($list, new NullLogger());

        $handler->handle($this->makeNotification(null));

        $this->assertCount(1, $list->suppressed);
    }
}
