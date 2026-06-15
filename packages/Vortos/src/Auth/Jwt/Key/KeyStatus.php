<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt\Key;

/**
 * Lifecycle status of a signing key within a {@see Keyring}.
 *
 * A keyring always has exactly one Active key (the current signer). During
 * rotation, a new key is added as Next (verified but not yet signing) and the
 * previous Active key becomes Retiring (no longer signs, still verifies live
 * tokens until they age out). Membership in the ring is what makes a key a
 * valid verifier — drop a Retiring key from the ring once no token it signed
 * can still be alive (i.e. after max(refreshTokenTtl) has elapsed).
 */
enum KeyStatus: string
{
    /** The current signer. Exactly one Active key per keyring. */
    case Active = 'active';

    /** Staged for the next rotation — verifies tokens but does not sign yet. */
    case Next = 'next';

    /** Previous signer — no longer signs, still verifies tokens it issued. */
    case Retiring = 'retiring';
}
