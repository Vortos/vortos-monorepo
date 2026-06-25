<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Sink;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Observability\Sink\CapturedError;
use Vortos\Observability\Sink\ErrorSeverity;

final class CapturedErrorTest extends TestCase
{
    public function test_from_throwable_scrubs_message(): void
    {
        $error = CapturedError::fromThrowable(new RuntimeException('failed for user alice@example.com'));

        self::assertStringNotContainsString('alice@example.com', $error->message);
        self::assertSame(RuntimeException::class, $error->exceptionClass);
    }

    public function test_fingerprint_is_stable_for_same_class_and_site(): void
    {
        $a = CapturedError::fromThrowable($e = new RuntimeException('one'));
        $b = CapturedError::fromThrowable($e);

        self::assertSame($a->fingerprint, $b->fingerprint);
    }

    public function test_fingerprint_excludes_pii_message(): void
    {
        // Two errors of the same class/site but different (PII) messages still group.
        $line = __LINE__ + 2;
        $make = static fn (string $m): RuntimeException => new RuntimeException($m);
        $a = CapturedError::fromThrowable($make('user a@b.com'));
        $b = CapturedError::fromThrowable($make('user c@d.com'));

        self::assertSame($a->fingerprint, $b->fingerprint);
        self::assertSame($line, $line); // anchor
    }

    public function test_message_is_truncated(): void
    {
        $error = CapturedError::fromMessage(str_repeat('x', 5000));

        self::assertLessThanOrEqual(2000, strlen($error->message));
    }

    public function test_context_is_bounded(): void
    {
        $context = [];
        for ($i = 0; $i < 200; $i++) {
            $context["k{$i}"] = $i;
        }

        $error = CapturedError::fromMessage('m', ErrorSeverity::Warning, $context);

        self::assertLessThanOrEqual(50, count($error->context));
    }

    public function test_to_array_shape(): void
    {
        $error = CapturedError::fromMessage('boom', ErrorSeverity::Fatal, ['a' => 1]);
        $array = $error->toArray();

        self::assertSame('fatal', $array['severity']);
        self::assertSame('boom', $array['message']);
        self::assertArrayHasKey('fingerprint', $array);
        self::assertArrayHasKey('occurredAt', $array);
    }

    public function test_severity_rank_ordering(): void
    {
        self::assertGreaterThan(ErrorSeverity::Warning->rank(), ErrorSeverity::Error->rank());
        self::assertGreaterThan(ErrorSeverity::Error->rank(), ErrorSeverity::Fatal->rank());
    }
}
