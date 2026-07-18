<?php

declare(strict_types=1);

namespace Vortos\Push\Vapid;

use Firebase\JWT\JWT;
use Vortos\Push\Config\VapidKeys;

/**
 * Builds the VAPID `Authorization: vapid t=<jwt>, k=<publicKey>` header for a
 * push endpoint (RFC 8292). The JWT is ES256, signed with the VAPID EC private
 * key and scoped to the endpoint's origin as its audience.
 */
final class VapidHeaderFactory
{
    public function __construct(
        private readonly VapidKeys $keys,
    ) {}

    public function authorizationHeader(string $endpoint): string
    {
        $payload = [
            'aud' => $this->origin($endpoint),
            'exp' => time() + 12 * 3600, // RFC 8292: <= 24h.
            'sub' => $this->keys->subject(),
        ];

        $jwt = JWT::encode($payload, $this->keys->privateKeyPem(), 'ES256');

        return sprintf('vapid t=%s, k=%s', $jwt, $this->keys->publicKey());
    }

    private function origin(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Invalid push endpoint URL.');
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}
