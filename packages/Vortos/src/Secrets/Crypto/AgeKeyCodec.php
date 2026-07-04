<?php

declare(strict_types=1);

namespace Vortos\Secrets\Crypto;

use InvalidArgumentException;

/**
 * Decodes `age` key material into the raw 32-byte X25519 keys the envelope crypto uses, accepting
 * both the standard bech32 formats that `age-keygen` emits and raw base64 (for programmatic use):
 *
 *   - Public key:  `age1…` (bech32, HRP `age`)               — or base64 of the 32-byte X25519 pubkey
 *   - Identity:    `AGE-SECRET-KEY-1…` (bech32, HRP `age-secret-key-`) — or base64 of the 32-byte scalar
 *
 * Every path validates the decoded length is exactly 32 bytes and fails closed with a message that
 * names all accepted formats.
 */
final class AgeKeyCodec
{
    private const PUBLIC_HRP = 'age';
    private const IDENTITY_HRP = 'age-secret-key-';
    private const KEY_BYTES = 32;

    /** Decode an age recipient (public key) to the raw 32-byte X25519 public key. */
    public static function decodePublicKey(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^age1[0-9a-z]+$/i', $value) === 1) {
            return self::decodeBech32($value, self::PUBLIC_HRP, 'age public key');
        }

        return self::decodeBase64($value, 'age public key (age1… or base64 X25519)');
    }

    /** Decode an age identity (secret key) to the raw 32-byte X25519 secret scalar. */
    public static function decodeIdentity(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^AGE-SECRET-KEY-1[0-9A-Z]+$/i', $value) === 1) {
            return self::decodeBech32($value, self::IDENTITY_HRP, 'age identity');
        }

        return self::decodeBase64($value, 'age identity (AGE-SECRET-KEY-1… or base64 X25519)');
    }

    private static function decodeBech32(string $value, string $expectedHrp, string $label): string
    {
        try {
            $decoded = Bech32::decode($value);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException(sprintf('Invalid %s: %s', $label, $e->getMessage()), 0, $e);
        }

        if ($decoded['hrp'] !== $expectedHrp) {
            throw new InvalidArgumentException(sprintf(
                'Invalid %s: expected bech32 prefix "%s", got "%s".',
                $label,
                $expectedHrp,
                $decoded['hrp'],
            ));
        }

        return self::assertKeyLength($decoded['bytes'], $label);
    }

    private static function decodeBase64(string $value, string $label): string
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            throw new InvalidArgumentException(sprintf('Invalid %s: not a recognised age key format.', $label));
        }

        return self::assertKeyLength($decoded, $label);
    }

    private static function assertKeyLength(string $bytes, string $label): string
    {
        if (strlen($bytes) !== self::KEY_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'Invalid %s: decoded to %d bytes, expected %d (a raw X25519 key).',
                $label,
                strlen($bytes),
                self::KEY_BYTES,
            ));
        }

        return $bytes;
    }
}
