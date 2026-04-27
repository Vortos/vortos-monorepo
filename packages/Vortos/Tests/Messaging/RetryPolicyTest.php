<?php
declare(strict_types=1);

namespace Vortos\Tests\Messaging;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Retry\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function test_fixed_policy_has_correct_max_attempts(): void
    {
        $policy = RetryPolicy::fixed(attempts: 3, delayMs: 1000);
        $this->assertSame(3, $policy->maxAttempts);
    }

    public function test_fixed_policy_has_fixed_backoff(): void
    {
        $policy = RetryPolicy::fixed(attempts: 3, delayMs: 1000);
        $this->assertSame('fixed', $policy->backoffStrategy);
    }

    public function test_exponential_policy_has_correct_max_attempts(): void
    {
        $policy = RetryPolicy::exponential(attempts: 5, initialDelayMs: 500);
        $this->assertSame(5, $policy->maxAttempts);
    }

    public function test_exponential_policy_has_exponential_backoff(): void
    {
        $policy = RetryPolicy::exponential(attempts: 5, initialDelayMs: 500);
        $this->assertSame('exponential', $policy->backoffStrategy);
    }

    public function test_exponential_policy_has_jitter_by_default(): void
    {
        $policy = RetryPolicy::exponential(attempts: 3, initialDelayMs: 500);
        $this->assertTrue($policy->jitter);
    }

    public function test_exponential_policy_jitter_can_be_disabled(): void
    {
        $policy = RetryPolicy::exponential(attempts: 3, initialDelayMs: 500, jitter: false);
        $this->assertFalse($policy->jitter);
    }

    public function test_to_array_and_from_array_roundtrip(): void
    {
        $policy = RetryPolicy::exponential(attempts: 3, initialDelayMs: 500, maxDelayMs: 10000);
        $restored = RetryPolicy::fromArray($policy->toArray());
        $this->assertSame($policy->maxAttempts, $restored->maxAttempts);
        $this->assertSame($policy->backoffStrategy, $restored->backoffStrategy);
        $this->assertSame($policy->initialDelayMs, $restored->initialDelayMs);
    }

    public function test_from_array_uses_defaults_for_missing_keys(): void
    {
        $policy = RetryPolicy::fromArray([]);
        $this->assertSame(3, $policy->maxAttempts);
        $this->assertSame('exponential', $policy->backoffStrategy);
    }
}
