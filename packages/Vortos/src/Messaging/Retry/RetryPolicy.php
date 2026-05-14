<?php

declare(strict_types=1);

namespace Vortos\Messaging\Retry;

/**
 * Immutable value object describing the retry behavior for a consumer.
 *
 * Use the named static factories rather than the constructor.
 * The jitter flag adds randomness to backoff delays to prevent multiple
 * consumers from retrying simultaneously after a shared failure (thundering herd).
 *
 * Example:
 *   RetryPolicy::exponential(attempts: 5, initialDelayMs: 500)
 *   RetryPolicy::fixed(attempts: 3, delayMs: 2000)
 */
final class RetryPolicy
{
    private function __construct(
        public readonly int $maxAttempts,
        public readonly string $backoffStrategy, # 'fixed' or 'exponential'
        public readonly int $initialDelayMs,
        public readonly int $maxDelayMs,
        public readonly float $multiplier,
        public readonly int $maxReplayLimit,
        public readonly bool $jitter,
    ) {}

    /**
     * Creates a fixed backoff policy where every retry waits the same delay.
     * Use for simple cases where predictable retry timing is preferred.
     */
    public static function fixed(int $attempts, int $delayMs, int $maxReplayLimit = 10): self
    {
        return new self(
            $attempts,
            'fixed',
            $delayMs,
            $delayMs,
            1.0,
            $maxReplayLimit,
            false
        );
    }

    /**
     * Creates an exponential backoff policy where delay doubles each attempt.
     * Jitter is enabled by default to prevent thundering herd across consumers.
     */
    public static function exponential(int $attempts, int $initialDelayMs, int $maxDelayMs = 30000, int $maxReplayLimit = 10, bool $jitter = true): self
    {
        return new self(
            $attempts,
            'exponential',
            $initialDelayMs,
            $maxDelayMs,
            2.0,
            $maxReplayLimit,
            $jitter
        );
    }

    /** Serialize to array for storage or transmission. Reversible via fromArray(). */
    public function toArray(): array
    {
        return [
            'maxAttempts' => $this->maxAttempts,
            'backoff' => $this->backoffStrategy,
            'initialDelay' => $this->initialDelayMs,
            'maxDelay' => $this->maxDelayMs,
            'multiplier' => $this->multiplier,
            'jitter' => $this->jitter,
            'maxReplayLimit' => $this->maxReplayLimit,
        ];
    }

    /**
     * Reconstruct a RetryPolicy from a config array.
     * Missing keys fall back to sensible defaults — sparse arrays are acceptable.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            $config['maxAttempts'] ?? 3,
            $config['backoff'] ?? 'exponential',
            $config['initialDelay'] ?? 500,
            $config['maxDelay'] ?? 30000,
            $config['multiplier'] ?? 2.0,
            $config['maxReplayLimit'] ?? 10,
            $config['jitter'] ?? true,
        );
    }
}