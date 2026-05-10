<?php

declare(strict_types=1);

namespace Vortos\Security\Encryption;

use Vortos\Security\Contract\SecretsInterface;

/**
 * Derives per-context sub-keys from a single master key using HKDF-SHA256.
 *
 * ## Why HKDF instead of using the master key directly?
 *
 * HKDF (RFC 5869) lets us derive independent sub-keys per field/context from
 * one master key. This means:
 *
 *  - If a sub-key is compromised, the master key and other field keys are safe.
 *  - Rotating the master key creates new sub-keys for all contexts — without
 *    the cryptographic relationship between old and new keys.
 *  - Re-encryption of existing data can be done lazily (read old → decrypt →
 *    encrypt with new key → write back).
 *
 * ## Master key format
 *
 * The master key must be exactly 32 bytes (256 bits), stored base64-encoded.
 * Generate a safe key: base64_encode(random_bytes(32))
 */
final class KeyDerivationService
{
    private string $masterKey;

    public function __construct(
        private readonly string           $masterKeyEnv,
        private readonly SecretsInterface $secrets,
    ) {}

    /**
     * Derives a 32-byte sub-key for the given context string.
     *
     * The same context always produces the same sub-key from the same master key.
     * Different contexts produce cryptographically independent sub-keys.
     *
     * @param string $context Field name or domain context (e.g. 'user.ssn', 'payment.card_number')
     */
    public function deriveKey(string $context): string
    {
        $masterKey = $this->loadMasterKey();

        // HKDF-SHA256: extract then expand
        // Salt: zero bytes (master key already has sufficient entropy)
        $prk = hash_hmac('sha256', $masterKey, str_repeat("\x00", 32), true);

        // Expand with context as info
        $info   = 'vortos-encryption:' . $context;
        $subKey = hash_hmac('sha256', $info . "\x01", $prk, true);

        return $subKey; // 32 bytes
    }

    private function loadMasterKey(): string
    {
        if (isset($this->masterKey)) {
            return $this->masterKey;
        }

        $encoded = $this->secrets->get($this->masterKeyEnv);
        if ($encoded === '') {
            throw new \RuntimeException(
                "vortos-security: encryption master key not found in environment variable '{$this->masterKeyEnv}'. "
                . "Generate one with: base64_encode(random_bytes(32))"
            );
        }

        $decoded = base64_decode($encoded, strict: true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException(
                "vortos-security: encryption master key in '{$this->masterKeyEnv}' must be exactly 32 bytes, "
                . "base64-encoded. Generate one with: base64_encode(random_bytes(32))"
            );
        }

        $this->masterKey = $decoded;
        return $this->masterKey;
    }
}
