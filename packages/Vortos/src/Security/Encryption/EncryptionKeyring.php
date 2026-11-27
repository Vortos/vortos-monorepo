<?php

declare(strict_types=1);

namespace Vortos\Security\Encryption;

use SensitiveParameter;
use Vortos\Security\Contract\SecretsInterface;

/**
 * The keys field-level encryption may use: one active key that encrypts, and any number of retired
 * keys that only decrypt.
 *
 * ## Rotation
 *
 * Rotating a key is a configuration change, never a flag day:
 *
 *   1. Generate a new key. Move the current one into the retired list, make the new one active.
 *   2. Deploy. New values are encrypted under the new key; values under the retired key still
 *      decrypt, and EncryptionInterface::needsReencryption() names them.
 *   3. Re-encrypt those values (a sweep the application owns, since only it knows where they live).
 *   4. Once nothing needs re-encryption, drop the retired key.
 *
 * The pattern is the keyset of Google Tink and the previous-keys list of Rails and Laravel.
 *
 * ## Key ids
 *
 * Every ciphertext names the key it was made with, so decryption never has to try keys in turn. A
 * key's id is the first four bytes of HMAC-SHA256(key, "vortos-encryption/key-id"): stable,
 * derived rather than configured (so it cannot be mislabelled), and revealing nothing of the key.
 *
 * ## Sub-keys
 *
 * Each (key, context) pair gets its own 32-byte sub-key, by HKDF-SHA256 (RFC 5869) with the
 * context as `info`. A sub-key exposed for one field says nothing about another field's.
 *
 * ## Format of the configuration
 *
 * Both variables hold base64 of exactly 32 random bytes; the retired variable holds a
 * comma-separated list. Generate a key with: php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
 * A key of the wrong length, or the same key twice, is refused rather than stretched or ignored.
 */
final class EncryptionKeyring
{
    public const KEY_BYTES    = 32;
    public const KEY_ID_BYTES = 4;

    /** @var array<string, string>|null key id => raw key, the active key first */
    private ?array $keys = null;
    private ?string $activeKeyId = null;

    public function __construct(
        private readonly string $activeKeyEnv,
        private readonly SecretsInterface $secrets,
        private readonly string $retiredKeysEnv = '',
    ) {}

    /** The id of the key that encrypts. */
    public function activeKeyId(): string
    {
        $this->load();

        return (string) $this->activeKeyId;
    }

    public function holds(string $keyId): bool
    {
        return isset($this->load()[$keyId]);
    }

    /**
     * Every key id this keyring can decrypt with, the active key first.
     *
     * @return list<string>
     */
    public function keyIds(): array
    {
        return array_keys($this->load());
    }

    /**
     * The 32-byte sub-key of $keyId for $context.
     *
     * @throws \RuntimeException when this keyring does not hold $keyId
     */
    public function subKey(string $keyId, string $context): string
    {
        $keys = $this->load();
        if (!isset($keys[$keyId])) {
            throw new \RuntimeException(sprintf(
                'vortos-security: the value was encrypted with key %s, which this deployment does not hold. Restore it to %s.',
                bin2hex($keyId),
                $this->retiredKeysEnv !== '' ? $this->retiredKeysEnv : $this->activeKeyEnv,
            ));
        }

        return hash_hkdf('sha256', $keys[$keyId], self::KEY_BYTES, 'vortos-encryption:' . $context, '');
    }

    public static function keyIdOf(#[SensitiveParameter] string $rawKey): string
    {
        return substr(hash_hmac('sha256', 'vortos-encryption/key-id', $rawKey, true), 0, self::KEY_ID_BYTES);
    }

    /** @return array<string, string> */
    private function load(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $active = $this->decode($this->secrets->get($this->activeKeyEnv), $this->activeKeyEnv, required: true);
        $keys   = [self::keyIdOf($active) => $active];

        if ($this->retiredKeysEnv !== '') {
            foreach (explode(',', $this->secrets->get($this->retiredKeysEnv)) as $encoded) {
                if (trim($encoded) === '') {
                    continue;
                }

                $raw = $this->decode($encoded, $this->retiredKeysEnv, required: true);
                $id  = self::keyIdOf($raw);
                if (isset($keys[$id])) {
                    throw new \RuntimeException(sprintf(
                        'vortos-security: %s lists a key twice, or lists the active key. Each key appears once.',
                        $this->retiredKeysEnv,
                    ));
                }
                $keys[$id] = $raw;
            }
        }

        $this->activeKeyId = array_key_first($keys);

        return $this->keys = $keys;
    }

    private function decode(#[SensitiveParameter] string $encoded, string $env, bool $required): string
    {
        $encoded = trim($encoded);
        if ($encoded === '' && $required) {
            throw new \RuntimeException(sprintf(
                "vortos-security: encryption key not found in '%s'. Generate one with: base64_encode(random_bytes(32))",
                $env,
            ));
        }

        $raw = base64_decode($encoded, strict: true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            throw new \RuntimeException(sprintf(
                "vortos-security: every key in '%s' must be exactly 32 bytes, base64-encoded. Generate one with: base64_encode(random_bytes(32))",
                $env,
            ));
        }

        return $raw;
    }
}
