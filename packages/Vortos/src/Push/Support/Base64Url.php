<?php

declare(strict_types=1);

namespace Vortos\Push\Support;

/**
 * Unpadded base64url (RFC 4648 §5) — the encoding Web Push uses for keys and
 * the VAPID public key. Self-contained so the package carries no extra
 * dependency for a few lines of transform.
 */
final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        $padded = strtr($text, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            throw new \InvalidArgumentException('Invalid base64url input.');
        }

        return $decoded;
    }
}
