<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Contract;

use Symfony\Component\HttpFoundation\Request;
use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Verifies if 2FA has been completed for the current identity.
 * Auto-discovered — just implement this interface.
 *
 * Example:
 *   class TotpVerifier implements TwoFactorVerifierInterface
 *   {
 *       public function isVerified(UserIdentityInterface $identity, Request $request): bool
 *       {
 *           $verifiedAt = $request->getSession()->get('2fa_verified_at_' . $identity->id());
 *           return $verifiedAt && (time() - $verifiedAt) < 300;
 *       }
 *       public function getChallengeUrl(): string { return '/auth/2fa/challenge'; }
 *   }
 */
interface TwoFactorVerifierInterface
{
    public function isVerified(UserIdentityInterface $identity, Request $request): bool;
    public function getChallengeUrl(): string;
}
