<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Attribute;

use Vortos\Auth\RateLimit\RateLimitScope;

/**
 * Apply rate limiting to a controller or method.
 *
 * Multiple attributes can be stacked — all policies are checked:
 *
 *   #[RateLimit(SubscriptionRateLimitPolicy::class, per: RateLimitScope::User)]
 *   #[RateLimit(GlobalBurstPolicy::class, per: RateLimitScope::Ip)]
 *   public function createPost(): Response { ... }
 *
 * String or class-string for the policy — both work:
 *   #[RateLimit('App\Policy\SubscriptionRateLimitPolicy')]
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RateLimit
{
    public function __construct(
        public readonly string $policy,
        public readonly RateLimitScope $per = RateLimitScope::User,
    ) {}
}
