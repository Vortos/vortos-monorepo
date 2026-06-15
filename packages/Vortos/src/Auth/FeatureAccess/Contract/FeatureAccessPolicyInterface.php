<?php
declare(strict_types=1);

namespace Vortos\Auth\FeatureAccess\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Defines feature access rules for a given identity.
 *
 * Auto-discovered — just implement this interface.
 * The feature string is already normalised (a BackedEnum on the attribute is
 * resolved to its ->value before it reaches here).
 *
 * Return a FeatureAccessDecision, not a bool: the policy is the only layer that
 * knows *why* a denial happens, so it — not the route — decides whether a denial
 * is 403 (plan does not include the feature) or 402 (entitled but lapsed).
 *
 * Example:
 *   class SubscriptionFeaturePolicy implements FeatureAccessPolicyInterface
 *   {
 *       private const PLAN_FEATURES = [
 *           'free' => ['api.basic'],
 *           'pro'  => ['api.basic', 'api.bulk_export', 'api.webhooks'],
 *       ];
 *
 *       public function evaluate(UserIdentityInterface $identity, string $feature): FeatureAccessDecision
 *       {
 *           $plan = $identity->getAttribute('plan') ?? 'free';
 *           if (!in_array($feature, self::PLAN_FEATURES[$plan] ?? [], true)) {
 *               return FeatureAccessDecision::Forbidden;
 *           }
 *           return $identity->getAttribute('subscription_active') === false
 *               ? FeatureAccessDecision::PaymentRequired
 *               : FeatureAccessDecision::Allowed;
 *       }
 *   }
 */
interface FeatureAccessPolicyInterface
{
    public function evaluate(UserIdentityInterface $identity, string $feature): FeatureAccessDecision;
}
