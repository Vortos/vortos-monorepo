<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\RateLimit;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\RateLimit\InMemoryTokenBucket;

final class InMemoryTokenBucketTest extends TestCase
{
    public function test_initially_full_can_consume_up_to_burst(): void
    {
        $bucket = new InMemoryTokenBucket(maxRate: 10, burst: 5);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($bucket->tryConsume(), "Expected token at iteration $i");
        }
    }

    public function test_returns_false_when_empty(): void
    {
        $bucket = new InMemoryTokenBucket(maxRate: 1, burst: 1);
        $bucket->tryConsume(); // drain

        $this->assertFalse($bucket->tryConsume());
    }

    public function test_refills_over_time(): void
    {
        // Rate = 1000 tokens/sec, burst = 1. Drain then wait ≥1ms.
        $bucket = new InMemoryTokenBucket(maxRate: 1000, burst: 1);
        $bucket->tryConsume(); // drain

        // Sleeping 2ms should add ~2 tokens (rate=1000/s), allowing a consume
        usleep(2_000);

        $this->assertTrue($bucket->tryConsume());
    }

    public function test_does_not_exceed_burst_on_refill(): void
    {
        $bucket = new InMemoryTokenBucket(maxRate: 1000, burst: 3);
        // Bucket starts full (3). Wait a long time — should still be capped at 3.
        usleep(10_000);

        // Should be able to consume exactly 3 tokens, not more.
        $consumed = 0;
        while ($bucket->tryConsume()) {
            ++$consumed;
            if ($consumed > 10) {
                break; // safety: prevent infinite loop on bug
            }
        }

        $this->assertSame(3, $consumed);
    }

    public function test_high_rate_allows_many_sequential_consumes_with_sleep(): void
    {
        $bucket = new InMemoryTokenBucket(maxRate: 5000, burst: 2);
        $bucket->tryConsume();
        $bucket->tryConsume(); // drain

        usleep(5_000); // 5ms → 5000/s * 0.005s = 25 new tokens, capped to 2

        $this->assertTrue($bucket->tryConsume());
        $this->assertTrue($bucket->tryConsume());
        $this->assertFalse($bucket->tryConsume()); // empty again
    }
}
