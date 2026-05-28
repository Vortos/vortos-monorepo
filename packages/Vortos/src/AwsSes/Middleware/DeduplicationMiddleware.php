<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Middleware;

use Vortos\AwsSes\Attribute\AsEmailMiddleware;
use Vortos\AwsSes\Contract\EmailMiddlewareInterface;
use Vortos\AwsSes\Deduplication\DeduplicationStoreInterface;
use Vortos\AwsSes\ValueObject\Email;
use Vortos\AwsSes\ValueObject\SentEmail;

/**
 * Prevents duplicate sends when the email carries an idempotency key.
 *
 * Priority 850 — runs early (before tracing, suppression, rate-limit) so
 * duplicate sends are short-circuited cheaply, consuming no quota.
 *
 * Idempotency key resolution order:
 *   1. Email::getMeta('idempotency_key') — explicit key set by application code
 *   2. Email::getMeta('domain_event_id') — set by the outbox relay worker when
 *      replaying domain events to prevent double-sends on crash-restart
 *
 * When no key is present the email is sent unconditionally (no deduplication).
 *
 * Successful sends are cached with a 24-hour TTL. If the same key appears again
 * within the TTL window, the original SentEmail is returned without a network call.
 */
#[AsEmailMiddleware(priority: 850)]
final class DeduplicationMiddleware implements EmailMiddlewareInterface
{
    public function __construct(
        private readonly DeduplicationStoreInterface $store,
        private readonly int $ttlSeconds = 86400,
    ) {}

    public function process(Email $email, callable $next): SentEmail
    {
        $key = $email->getMeta('idempotency_key') ?? $email->getMeta('domain_event_id');

        if ($key === null) {
            return $next($email);
        }

        if ($this->store->isDuplicate($key)) {
            $cached = $this->store->getSent($key);
            if ($cached !== null) {
                return $cached;
            }
        }

        $result = $next($email);

        $this->store->markSent($key, $result, $this->ttlSeconds);

        return $result;
    }
}
