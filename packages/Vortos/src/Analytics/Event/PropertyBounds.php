<?php

declare(strict_types=1);

namespace Vortos\Analytics\Event;

/**
 * Shared, deterministic bounding for every property/trait bag that crosses the
 * analytics boundary (events, identify traits, group traits).
 *
 * Both a cost guard and a DoS guard (roadmap card): a caller flooding `capture()`
 * with thousands of properties or one gigantic value must be truncated
 * deterministically at construction, never forwarded as-is and never thrown.
 *
 * Truncation is deterministic and order-preserving: keys are kept in their
 * original iteration order, dropping from the tail first by count, then — if the
 * serialized size is still over budget — dropping further keys from the tail until
 * it fits.
 */
final class PropertyBounds
{
    /**
     * @param array<array-key,mixed> $properties untrusted: keys are validated, not assumed
     * @return array<string,mixed>
     */
    public static function bound(array $properties, int $maxCount, int $maxBytes): array
    {
        $bounded = [];
        $i = 0;
        foreach ($properties as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue; // unkeyed/empty-keyed entries are junk, never forwarded
            }
            if ($i >= $maxCount) {
                break;
            }
            $bounded[$key] = $value;
            $i++;
        }

        while ($bounded !== [] && self::serializedSize($bounded) > $maxBytes) {
            array_pop($bounded);
        }

        return $bounded;
    }

    /** @param array<string,mixed> $properties */
    private static function serializedSize(array $properties): int
    {
        $encoded = json_encode($properties);

        return $encoded === false ? PHP_INT_MAX : strlen($encoded);
    }
}
