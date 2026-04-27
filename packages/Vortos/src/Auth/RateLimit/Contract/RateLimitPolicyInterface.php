<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\RateLimit\RateLimitRule;

/**
 * Defines the rate limit rule for a given identity.
 *
 * Implement this interface to define custom rate limiting strategies.
 * Auto-discovered — just implement this interface, no registration needed.
 *
 * Example:
 *   class SubscriptionRateLimitPolicy implements RateLimitPolicyInterface
 *   {
 *       public function getLimit(UserIdentityInterface $identity): RateLimitRule
 *       {
 *           return match($identity->getAttribute('plan') ?? 'free') {
 *               'pro'  => new RateLimitRule(1000, 60),
 *               default => new RateLimitRule(100, 60),
 *           };
 *       }
 *   }
 */
interface RateLimitPolicyInterface
{
    public function getLimit(UserIdentityInterface $identity): RateLimitRule;
}
