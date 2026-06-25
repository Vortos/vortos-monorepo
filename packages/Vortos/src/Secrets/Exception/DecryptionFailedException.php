<?php

declare(strict_types=1);

namespace Vortos\Secrets\Exception;

use RuntimeException;

/**
 * Raised whenever the AEAD authentication tag fails to verify, or a sealed-box
 * cannot be opened with the supplied identity. Fail-closed: a tampered envelope, a
 * truncated ciphertext, or the wrong identity NEVER produces partial or garbage
 * plaintext — it always raises.
 */
final class DecryptionFailedException extends RuntimeException implements SecretsException
{
    public static function authenticationFailed(): self
    {
        return new self('Envelope authentication failed: ciphertext, nonce, or AAD has been tampered with.');
    }

    public static function keyUnwrapFailed(): self
    {
        return new self('Failed to unwrap the data key: wrong identity, or the wrapped key has been tampered with.');
    }
}
