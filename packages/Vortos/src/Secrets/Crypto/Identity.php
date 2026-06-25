<?php

declare(strict_types=1);

namespace Vortos\Secrets\Crypto;

use InvalidArgumentException;
use Vortos\Secrets\Value\SecretValue;

/**
 * An X25519 keypair identity used to seal/unseal a {@see SecretEnvelope}'s data
 * key — the off-host "private key" in the `age`-style custody model.
 *
 * The full keypair (secret + public key, libsodium's combined 64-byte form) is held
 * inside a {@see SecretValue} so it is never logged/dumped by accident; the public
 * key alone is exposed in the clear since it is, by definition, not secret.
 *
 * Construction is deliberately the only place raw key material is handled — callers
 * (drivers) get an opaque {@see Identity} and never see the secret key bytes
 * directly.
 */
final class Identity
{
    private function __construct(
        private readonly SecretValue $keyPair,
        private readonly string $publicKey,
    ) {}

    /** Generates a fresh random identity. Primarily for tests/bootstrap tooling. */
    public static function generate(): self
    {
        $keyPair = sodium_crypto_box_keypair();
        $publicKey = sodium_crypto_box_publickey($keyPair);

        return new self(SecretValue::fromString($keyPair), $publicKey);
    }

    /** @param string $secretKeySeed raw 32-byte X25519 secret key */
    public static function fromSecretKeySeed(string $secretKeySeed): self
    {
        if (strlen($secretKeySeed) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            throw new InvalidArgumentException(sprintf(
                'Identity secret key seed must be %d bytes, got %d.',
                SODIUM_CRYPTO_BOX_SECRETKEYBYTES,
                strlen($secretKeySeed),
            ));
        }

        $publicKey = sodium_crypto_box_publickey_from_secretkey($secretKeySeed);
        $keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKeySeed, $publicKey);

        return new self(SecretValue::fromString($keyPair), $publicKey);
    }

    /** @param string $base64Seed base64-encoded raw 32-byte X25519 secret key */
    public static function fromBase64Seed(string $base64Seed): self
    {
        $decoded = base64_decode($base64Seed, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Identity seed is not valid base64.');
        }

        return self::fromSecretKeySeed($decoded);
    }

    /** The public key, in the clear — not secret by definition. */
    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function publicKeyBase64(): string
    {
        return base64_encode($this->publicKey);
    }

    /**
     * The combined keypair, for {@see EnvelopeCipher::open()} only. Deliberately
     * named distinctly from a generic getter so every call site is a visible,
     * auditable boundary crossing — mirrors {@see SecretValue::reveal()}.
     */
    public function revealKeyPairForUnsealing(): string
    {
        return $this->keyPair->reveal();
    }
}
