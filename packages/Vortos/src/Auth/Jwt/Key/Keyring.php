<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt\Key;

use Firebase\JWT\Key;

/**
 * An ordered set of {@see SigningKey}s with exactly one Active signer.
 *
 * This is the unit of JWT key configuration in Vortos. It enables zero-downtime
 * rotation: tokens are always signed by {@see self::activeSigningKey()}, but
 * verified against {@see self::verificationKeys()} — every key in the ring — so
 * a token signed by a now-retiring key still validates until it expires.
 *
 * Invariants enforced at construction:
 *   - at least one key
 *   - unique kids
 *   - exactly one key with status Active
 *   - a single algorithm across the ring (no mixing HS256 and RS256)
 */
final readonly class Keyring
{
    /** @var list<SigningKey> */
    public array $keys;

    public function __construct(SigningKey ...$keys)
    {
        if ($keys === []) {
            throw new \InvalidArgumentException('A keyring must contain at least one signing key.');
        }

        $kids = [];
        $activeCount = 0;
        $algorithm = $keys[0]->algorithm;

        foreach ($keys as $key) {
            if (isset($kids[$key->kid])) {
                throw new \InvalidArgumentException("Duplicate kid '{$key->kid}' in keyring — kids must be unique.");
            }
            $kids[$key->kid] = true;

            if ($key->algorithm !== $algorithm) {
                throw new \InvalidArgumentException(
                    'A keyring cannot mix algorithms — found both ' .
                    "'{$algorithm}' and '{$key->algorithm}'. Use one algorithm per keyring."
                );
            }

            if ($key->status === KeyStatus::Active) {
                $activeCount++;
            }
        }

        if ($activeCount !== 1) {
            throw new \InvalidArgumentException(
                "A keyring must have exactly one Active key; found {$activeCount}."
            );
        }

        $this->keys = array_values($keys);
    }

    /**
     * Convenience builder for a single HS256 secret — the common single-service case.
     */
    public static function fromSecret(string $secret, string $kid = 'default'): self
    {
        return new self(SigningKey::hs256($kid, $secret));
    }

    /**
     * The current signer. Exactly one key is Active.
     */
    public function activeSigningKey(): SigningKey
    {
        foreach ($this->keys as $key) {
            if ($key->status === KeyStatus::Active) {
                return $key;
            }
        }

        // Unreachable — the constructor guarantees exactly one Active key.
        throw new \LogicException('Keyring has no Active signing key.');
    }

    /**
     * All keys mapped by kid, as firebase/php-jwt Key objects, for JWT::decode().
     * The library resolves the correct key from the token's `kid` header.
     *
     * @return array<string, Key>
     */
    public function verificationKeys(): array
    {
        $map = [];
        foreach ($this->keys as $key) {
            $map[$key->kid] = $key->verificationKey();
        }

        return $map;
    }

    /**
     * The algorithm shared by every key in the ring.
     */
    public function algorithm(): string
    {
        return $this->keys[0]->algorithm;
    }

    public function isRsa(): bool
    {
        return $this->keys[0]->isRsa();
    }
}
