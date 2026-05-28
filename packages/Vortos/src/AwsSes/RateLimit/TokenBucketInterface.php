<?php

declare(strict_types=1);

namespace Vortos\AwsSes\RateLimit;

/**
 * Token bucket for rate limiting email sends.
 *
 * tryConsume() attempts to consume one token and returns true when the send
 * is allowed. Returns false when the bucket is empty — the caller decides
 * whether to spin-wait or throw.
 */
interface TokenBucketInterface
{
    public function tryConsume(): bool;
}
