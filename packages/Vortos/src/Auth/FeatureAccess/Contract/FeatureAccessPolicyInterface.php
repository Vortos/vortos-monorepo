<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Defines feature access rules for a given identity.
 *
 * Auto-discovered — just implement this interface.
 * Accepts string or BackedEnum for feature — both work.
 *
 * Example:
 *   class SubscriptionFeaturePolicy implements FeatureAccessPolicyInterface
 *   {
 *       private const PLAN_FEATURES = [
 *           'free' => ['api.basic'],
 *           'pro'  => ['api.basic', 'api.bulk_export', 'api.webhooks'],
 *       ];
 *
 *       public function canAccess(UserIdentityInterface $identity, string $feature): bool
 *       {
 *           $plan = $identity->getAttribute('plan') ?? 'free';
 *           return in_array($feature, self::PLAN_FEATURES[$plan] ?? [], true);
 *       }
 *   }
 */
interface FeatureAccessPolicyInterface
{
    public function canAccess(UserIdentityInterface $identity, string $feature): bool;
}
