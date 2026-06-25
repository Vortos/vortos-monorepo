<?php

declare(strict_types=1);

namespace Vortos\Secrets\Key;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Secrets\Exception\DecryptionFailedException;
use Vortos\Secrets\Exception\KeyUnavailableException;

/**
 * The swap seam for off-host key custody — "how is a data key (DEK) wrapped for
 * storage and unwrapped for use", independent of payload encryption. Drivers:
 * `age` (in-core, this block — X25519 sealed-box); `kms-aws`, `vault-transit`
 * (deferred, future blocks). Reused as-is by Block 19/20 (backup envelope reuse).
 *
 * Each driver instance is configured with exactly one custody identity (public
 * key for wrap, off-host private key sourced at use-time for unwrap) — KEK-level
 * multi-recipient/rotation is out of scope here; secret-level rotation/grace is
 * handled separately by {@see \Vortos\Secrets\Rotation\RotationPolicy}.
 */
interface KeyProviderInterface extends DriverInterface
{
    public function wrap(DataKey $dataKey): WrappedKey;

    /**
     * @throws DecryptionFailedException if unwrapping fails (wrong key, tampered ciphertext)
     * @throws KeyUnavailableException if the off-host identity is not configured/reachable
     */
    public function unwrap(WrappedKey $wrappedKey): DataKey;
}
