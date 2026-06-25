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
    public function getChallengeUrl(): string;
}
