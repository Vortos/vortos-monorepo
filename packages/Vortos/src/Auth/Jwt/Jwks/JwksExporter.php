<?php

declare(strict_types=1);

namespace Vortos\Auth\Jwt\Jwks;

use Vortos\Auth\Jwt\Key\Keyring;

/**
 * Builds a JWKS (JSON Web Key Set) document from a {@see Keyring}.
 *
 * Only RS256 keys are exported — a JWKS publishes *public* verification keys so
 * other services can verify tokens this app issues without sharing a secret.
 * HS256 keyrings produce an empty set (their secret must never be published),
 * and {@see self::isSupported()} returns false so the endpoint can 404.
 *
 * Output shape (RFC 7517):
 *   { "keys": [ { "kty":"RSA","use":"sig","alg":"RS256","kid":"…","n":"…","e":"…" } ] }
 */
final class JwksExporter
{
    public function __construct(private readonly Keyring $keyring) {}

    /**
     * Whether this keyring can be published as a JWKS (RS256 only).
     */
    public function isSupported(): bool
    {
        return $this->keyring->isRsa();
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function export(): array
    {
        if (!$this->isSupported()) {
            return ['keys' => []];
        }

        $keys = [];
        foreach ($this->keyring->keys as $key) {
            $jwk = $this->publicKeyToJwk($key->publicKey, $key->kid);
            if ($jwk !== null) {
                $keys[] = $jwk;
            }
        }

        return ['keys' => $keys];
    }

    /**
     * Convert an RSA public key PEM into its JWK representation.
     *
     * @return array<string, string>|null null if the PEM cannot be parsed as RSA
     */
    private function publicKeyToJwk(string $publicKeyPem, string $kid): ?array
    {
        $resource = openssl_pkey_get_public($publicKeyPem);
        if ($resource === false) {
            return null;
        }

        $details = openssl_pkey_get_details($resource);
        if ($details === false || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            return null;
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => $this->base64UrlEncode($details['rsa']['n']),
            'e'   => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
