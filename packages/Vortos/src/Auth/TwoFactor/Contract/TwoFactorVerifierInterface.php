<?php
declare(strict_types=1);

namespace Vortos\Auth\TwoFactor\Contract;

use Vortos\Http\Request;
use Vortos\Auth\Contract\UserIdentityInterface;

/**
 * Verifies if 2FA has been completed for the current identity.
 * Auto-discovered — just implement this interface.
 *
 * Implementation guidance:
 * - Bind the verification to the current session ID AND access-token jti
 * - Use a single-use challenge nonce with short TTL (≤300s)
 * - Never rely solely on a session timestamp — include device/session binding
 */
interface TwoFactorVerifierInterface
{
    public function isVerified(UserIdentityInterface $identity, Request $request): bool;

    /**
     * Where the caller must go to satisfy the challenge.
     *
     * The identity is passed because the answer usually depends on WHO is being challenged:
     * a deployment may put staff behind a hardware key and ordinary users behind a code, and
     * those are different URLs. Without it an implementation has to guess, and guessing wrong
     * points somebody at a challenge that cannot open the window they need — the request looks
     * broken rather than merely gated.
     *
     * Optional so existing implementations keep working unchanged; they may ignore it.
     */
    public function getChallengeUrl(?UserIdentityInterface $identity = null): string;
}
