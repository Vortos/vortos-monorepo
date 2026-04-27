<?php
declare(strict_types=1);

namespace Vortos\Auth\Session\Contract;

use Vortos\Auth\Contract\UserIdentityInterface;
use Vortos\Auth\Session\SessionLimitAction;

/**
 * Defines session limits for a given identity.
 * Auto-discovered — just implement this interface.
 *
 * Example:
 *   class SubscriptionSessionPolicy implements SessionPolicyInterface
 *   {
 *       public function getMaxSessions(UserIdentityInterface $identity): int
 *       {
 *           return $identity->getAttribute('plan') === 'pro' ? PHP_INT_MAX : 1;
 *       }
 *       public function onLimitExceeded(UserIdentityInterface $identity): SessionLimitAction
 *       {
 *           return SessionLimitAction::InvalidateOldest;
 *       }
 *   }
 */
interface SessionPolicyInterface
{
    public function getMaxSessions(UserIdentityInterface $identity): int;
    public function onLimitExceeded(UserIdentityInterface $identity): SessionLimitAction;
}
