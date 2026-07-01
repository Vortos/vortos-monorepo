<?php

declare(strict_types=1);

namespace Vortos\SchedulerAdmin\Security;

use Vortos\Auth\Contract\TokenFreshnessGuardInterface;
use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Checks 2FA verification status and token freshness for sensitive admin actions.
 *
 * 2FA check: reads the 'twofa_verified_at' claim from the user identity and
 * verifies it was set in the last $freshnessWindowSec seconds.
 *
 * Freshness check: delegates to TokenFreshnessGuardInterface (MinIatGuard).
 * The 'iat' claim must be present and within the freshness window.
 */
final class StepUpGuard
{
    public function __construct(
        private readonly ?TokenFreshnessGuardInterface $freshnessGuard,
        private readonly int                           $freshnessWindowSec = 900,
    ) {}

    public function check2FA(UserIdentityInterface $user): bool
    {
        $verifiedAt = $user->getAttribute('twofa_verified_at');

        if ($verifiedAt === null) {
            return false;
        }

        $verifiedAtTs = is_int($verifiedAt) ? $verifiedAt : (int) $verifiedAt;
        $now          = time();

        return ($now - $verifiedAtTs) <= $this->freshnessWindowSec;
    }

    public function checkFreshness(UserIdentityInterface $user): bool
    {
        $iat = $user->getAttribute('iat');

        if ($iat === null) {
            return false;
        }

        $issuedAt      = is_int($iat) ? $iat : (int) $iat;
        $authzVersion  = (int) $user->getAttribute('authz_version', 0);
        $rejectionMsg = $this->freshnessGuard?->check($user->id(), $authzVersion, $issuedAt);

        if ($rejectionMsg !== null) {
            return false;
        }

        $now = time();

        return ($now - $issuedAt) <= $this->freshnessWindowSec;
    }
}
