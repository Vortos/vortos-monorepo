<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Exception;

use Vortos\Auth\RateLimit\RateLimitScope;

final class RateLimitExceededException extends \RuntimeException
{
    public function __construct(
        public readonly RateLimitScope $scope,
        public readonly string $policy,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetAt,
        public readonly int $retryAfter,
    ) {
        parent::__construct(sprintf('Rate limit exceeded for scope "%s".', $scope->value));
    }
}
