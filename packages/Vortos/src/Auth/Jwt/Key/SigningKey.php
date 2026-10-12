<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt\Key;

use Firebase\JWT\Key;

/**
 * A single, kid-addressed JWT signing key.
 *
 * Wraps either an HS256 shared secret or an RS256 key pair, tagged with a
 * stable `kid` so tokens it signs carry that id in their header and verifiers
 * can resolve the right key from a {@see Keyring} during rotation.
 *
 * Construct via the named factories — {@see self::hs256()} / {@see self::rs256()} /
 * {@see self::rs256VerifyOnly()} —
 * never the constructor directly; they enforce the per-algorithm material rules.
 */
final readonly class SigningKey
{
    /**
     * @param string $kid       Stable key id. Emitted in the JWT `kid` header and JWKS.
     * @param string $algorithm 'HS256' or 'RS256'.
     * @param string $secret    HMAC secret (HS256 only; '' for RS256).
     * @param string $privateKey RSA private key PEM (RS256 only; '' for HS256).
     * @param string $publicKey  RSA public key PEM (RS256 only; '' for HS256).
     */
    private function __construct(
        public string $kid,
        public string $algorithm,
        public KeyStatus $status,
        public string $secret = '',
        public string $privateKey = '',
        public string $publicKey = '',
    ) {
        if ($kid === '') {
            throw new \InvalidArgumentException('A signing key must have a non-empty kid.');
        }
    }

    /**
     * HS256 symmetric key. The same secret signs and verifies.
     *
     * The secret must be at least 64 characters. Generate: bin2hex(random_bytes(32)).
     */
    public static function hs256(string $kid, string $secret, KeyStatus $status = KeyStatus::Active): self
    {
        if (strlen($secret) < 64) {
            throw new \InvalidArgumentException(
                "HS256 signing key '{$kid}' requires a secret of at least 64 characters. " .
                'Generate one with: bin2hex(random_bytes(32))'
            );
        }

        return new self($kid, 'HS256', $status, secret: $secret);
    }

    /**
     * RS256 asymmetric key. The private key signs; the public key (and JWKS) verifies.
     */
    public static function rs256(string $kid, string $privateKey, string $publicKey, KeyStatus $status = KeyStatus::Active): self
    {
        if ($privateKey === '' || $publicKey === '') {
            throw new \InvalidArgumentException(
                "RS256 signing key '{$kid}' requires both a private key and a public key PEM."
            );
        }

        return new self($kid, 'RS256', $status, privateKey: $privateKey, publicKey: $publicKey);
    }

    /**
     * The public half of an RS256 key only: verifies tokens, cannot issue them.
     *
     * For a process that authenticates nothing itself — a queue worker, the scheduler and backup
     * node — but boots the same configuration as the one that does. Without it every such node had
     * to be handed the private signing key, and anyone who took one of them could mint a token for
     * any user. {@see self::signingMaterial()} refuses on such a key, naming the kid, so a node that
     * unexpectedly tries to issue fails loudly instead of signing with something else.
     */
    public static function rs256VerifyOnly(string $kid, string $publicKey, KeyStatus $status = KeyStatus::Active): self
    {
        if ($publicKey === '') {
            throw new \InvalidArgumentException("RS256 verify-only key '{$kid}' requires a public key PEM.");
        }

        return new self($kid, 'RS256', $status, publicKey: $publicKey);
    }

    /** False for a verify-only key: this process holds no signing material for it. */
    public function canSign(): bool
    {
        return $this->isRsa() ? $this->privateKey !== '' : $this->secret !== '';
    }

    public function isRsa(): bool
    {
        return $this->algorithm === 'RS256';
    }

    /**
     * Key material used to SIGN tokens — the secret (HS256) or private key (RS256).
     */
    public function signingMaterial(): string
    {
        if (!$this->canSign()) {
            throw new \LogicException(sprintf(
                "Signing key '%s' is verify-only on this node: it was given the public key alone, so it cannot issue tokens. "
                . 'Only a node configured with the private key may sign.',
                $this->kid,
            ));
        }

        return $this->isRsa() ? $this->privateKey : $this->secret;
    }

    /**
     * A firebase/php-jwt verification Key — the secret (HS256) or public key (RS256).
     */
    public function verificationKey(): Key
    {
        return new Key($this->isRsa() ? $this->publicKey : $this->secret, $this->algorithm);
    }
}
