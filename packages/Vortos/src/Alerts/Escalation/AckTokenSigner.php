<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * HMAC-SHA256-signed, expiring acknowledgement tokens (§3.5, improvement #1 — the
 * security-critical case where "silence" is an attack). The key is held off-host via
 * `vortos-secrets`, read at use-time by the caller and passed in here, never logged.
 *
 * Token shape: `base64url(fingerprint|tier|exp) . '.' . hmac_sha256(payload, key)`.
 * Tampering with any field, letting it expire, or signing with a different key are
 * all rejected by {@see verify()} — never a silent pass.
 */
final class AckTokenSigner
{
    public function __construct(
        private readonly string $hmacKey,
    ) {
        if ($hmacKey === '') {
            throw new InvalidArgumentException('AckTokenSigner hmacKey must not be empty.');
        }
    }

    public function issue(string $fingerprint, int $tier, DateTimeImmutable $now, int $ttlSeconds = 900): string
    {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('AckTokenSigner ttlSeconds must be >= 1.');
        }

        $expiresAt = $now->getTimestamp() + $ttlSeconds;
        $payload = $this->encodePayload($fingerprint, $tier, $expiresAt);
        $signature = $this->sign($payload);

        return $payload . '.' . $signature;
    }

    /** @throws AckTokenException on any tampered/malformed/expired/wrong-key token */
    public function verify(string $token, DateTimeImmutable $now): AckTokenPayload
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new AckTokenException('Malformed ack token.');
        }
        [$payload, $signature] = $parts;

        if (!hash_equals($this->sign($payload), $signature)) {
            throw new AckTokenException('Ack token signature mismatch.');
        }

        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            throw new AckTokenException('Malformed ack token payload.');
        }

        $fields = explode('|', $decoded);
        if (count($fields) !== 3) {
            throw new AckTokenException('Malformed ack token payload.');
        }
        [$fingerprint, $tier, $expiresAt] = $fields;

        if (!ctype_digit($tier) || !ctype_digit(ltrim($expiresAt, '-'))) {
            throw new AckTokenException('Malformed ack token payload.');
        }

        $expiresAtInt = (int) $expiresAt;
        if ($expiresAtInt <= $now->getTimestamp()) {
            throw new AckTokenException('Ack token has expired.');
        }

        return new AckTokenPayload($fingerprint, (int) $tier, $expiresAtInt);
    }

    private function encodePayload(string $fingerprint, int $tier, int $expiresAt): string
    {
        $raw = sprintf('%s|%d|%d', $fingerprint, $tier, $expiresAt);

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->hmacKey);
    }
}
