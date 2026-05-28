<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\Middleware;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Exception\RateLimitExceededException;
use Vortos\AwsSes\Middleware\RateLimitMiddleware;
use Vortos\AwsSes\RateLimit\TokenBucketInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

final class RateLimitMiddlewareTest extends TestCase
{
    private function makeEmail(): Email
    {
        return Email::new()->to('u@example.com')->subject('S')->htmlBody('H');
    }

    private function makeSentEmail(): SentEmail
    {
        return new SentEmail('msg-1', new \DateTimeImmutable(), 1, 'log', null);
    }

    private function makeBucket(bool $hasToken): TokenBucketInterface
    {
        return new class($hasToken) implements TokenBucketInterface {
            public function __construct(private bool $hasToken) {}
            public function tryConsume(): bool { return $this->hasToken; }
        };
    }

    public function test_passes_through_when_token_available(): void
    {
        $mw   = new RateLimitMiddleware($this->makeBucket(true), waitTimeoutMs: 100);
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
    }

    public function test_throws_when_bucket_empty_and_timeout_zero(): void
    {
        $mw = new RateLimitMiddleware($this->makeBucket(false), waitTimeoutMs: 0);

        $this->expectException(RateLimitExceededException::class);
        $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());
    }

    public function test_throws_after_wait_timeout_expires(): void
    {
        $mw = new RateLimitMiddleware($this->makeBucket(false), waitTimeoutMs: 10);

        $start = hrtime(true);

        try {
            $mw->process($this->makeEmail(), fn($e) => $this->makeSentEmail());
            $this->fail('Expected RateLimitExceededException');
        } catch (RateLimitExceededException) {}

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        // Should have waited at least ~10ms (the timeout)
        $this->assertGreaterThanOrEqual(9.0, $elapsedMs);
    }

    public function test_succeeds_when_bucket_fills_before_timeout(): void
    {
        // Bucket that returns false the first few times then true
        $callCount = 0;
        $bucket = new class($callCount) implements TokenBucketInterface {
            public function __construct(public int &$callCount) {}
            public function tryConsume(): bool
            {
                return ++$this->callCount >= 3; // returns true on 3rd call
            }
        };

        $mw   = new RateLimitMiddleware($bucket, waitTimeoutMs: 100);
        $sent = $this->makeSentEmail();

        $result = $mw->process($this->makeEmail(), fn($e) => $sent);

        $this->assertSame($sent, $result);
        $this->assertGreaterThanOrEqual(3, $callCount);
    }

    public function test_exception_from_next_propagates(): void
    {
        $mw = new RateLimitMiddleware($this->makeBucket(true), waitTimeoutMs: 100);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('driver error');

        $mw->process($this->makeEmail(), fn($e) => throw new \RuntimeException('driver error'));
    }
}
