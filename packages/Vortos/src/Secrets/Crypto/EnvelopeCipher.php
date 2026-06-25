<?php

declare(strict_types=1);

namespace Vortos\Secrets\Crypto;

use Vortos\Secrets\Exception\DecryptionFailedException;

/**
 * AEAD payload encryption (XChaCha20-Poly1305) — the payload half of envelope
 * encryption. Deliberately knows nothing about recipients, identities, or key
 * custody: that is the {@see \Vortos\Secrets\Key\KeyProviderInterface}'s job
 * (wrap/unwrap the data key this class is given). Keeping the two halves separate
 * means the sealing/custody mechanism (`age` today, `kms-aws`/`vault-transit`
 * later) can change without touching payload encryption at all, and there is only
 * ONE place AEAD happens — no duplicate crypto code paths to drift apart.
 *
 * **Fail-closed by construction**: the AEAD authentication tag covers ciphertext +
 * AAD. A tampered ciphertext, nonce, or AAD — or simply the wrong data key — fails
 * verification and raises {@see DecryptionFailedException}, never partial or
 * garbage plaintext.
 */
final class EnvelopeCipher
{
    public const AAD = 'vortos-secrets-envelope-v1';

    /** A fresh, random 32-byte XChaCha20-Poly1305 data key (DEK). */
    public function generateDataKey(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    /**
     * @return array{ciphertext: string, nonce: string} binary ciphertext + 24-byte nonce
     */
    public function encryptPayload(string $plaintext, string $dek): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            self::AAD,
            $nonce,
            $dek,
        );

        return ['ciphertext' => $ciphertext, 'nonce' => $nonce];
    }

    public function decryptPayload(string $ciphertext, string $nonce, string $dek, string $aad = self::AAD): string
    {
        $plaintext = @sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $aad,
            $nonce,
            $dek,
        );

        if ($plaintext === false) {
            throw DecryptionFailedException::authenticationFailed();
        }

        return $plaintext;
    }
}
