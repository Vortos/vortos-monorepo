<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\Management;

/**
 * Encodes and decodes opaque keyset pagination cursors for management list endpoints.
 *
 * A cursor encodes the last-seen item's (id, at) pair so the next page query can
 * use a `(at, id) >` keyset condition instead of OFFSET, which is stable under
 * concurrent inserts.
 */
final class CursorEncoder
{
    public static function encode(string $id, \DateTimeImmutable $at): string
    {
        $json = json_encode(['id' => $id, 'at' => $at->format(\DateTimeInterface::ATOM)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @return array{id: string, at: string}|null */
    public static function decode(string $cursor): ?array
    {
        $padded = strtr($cursor, '-_', '+/');
        $rem    = strlen($padded) % 4;
        if ($rem !== 0) {
            $padded .= str_repeat('=', 4 - $rem);
        }

        $json = base64_decode($padded, true);
        if ($json === false) {
            return null;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) && isset($data['id'], $data['at']) ? $data : null;
    }
}
