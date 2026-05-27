<?php

declare(strict_types=1);

namespace Vortos\Ses\Middleware;

use Vortos\Ses\Attribute\AsEmailMiddleware;
use Vortos\Ses\Contract\EmailMiddlewareInterface;
use Vortos\Ses\Exception\RateLimitExceededException;
use Vortos\Ses\RateLimit\TokenBucketInterface;
use Vortos\Ses\ValueObject\Email;
use Vortos\Ses\ValueObject\SentEmail;

/**
 * Token-bucket rate limiter for outgoing email.
 *
 * Priority 550 — runs just above the driver so all earlier middleware
 * (suppression, deduplication, logging) executes before we consume a token.
 *
 * Spins up to $waitTimeoutMs waiting for a token, then raises
 * RateLimitExceededException. Set $waitTimeoutMs to 0 to fail immediately
 * when the bucket is empty (useful for outbox relay workers that prefer to
 * retry later rather than block a thread).
 */
#[AsEmailMiddleware(priority: 550)]
final class RateLimitMiddleware implements EmailMiddlewareInterface
{
    private const SPIN_SLEEP_US = 1_000; // 1 ms between token-check attempts

    public function __construct(
        private readonly TokenBucketInterface $tokenBucket,
        private readonly int $waitTimeoutMs,
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        $deadlineNs = hrtime(true) + ($this->waitTimeoutMs * 1_000_000);

        while (!$this->tokenBucket->tryConsume()) {
            if (hrtime(true) >= $deadlineNs) {
                throw new RateLimitExceededException(
                    'SES rate limit exceeded — no token available within wait timeout.',
                );
            }
            usleep(self::SPIN_SLEEP_US);
        }

        return $next($email);
    }
}
