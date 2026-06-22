<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Targeting;

/**
 * Deterministic, uniformly-distributed bucketing for rollouts and variant assignment.
 *
 * Uses MurmurHash3 (x86, 32-bit) — the industry-standard non-cryptographic hash for
 * flag bucketing (the same family LaunchDarkly/Unleash/Statsig use). Buckets span
 * `0..9999` (0.01% granularity) so sub-percent ramps are possible and splits are even.
 *
 * **Stability is a hard guarantee.** A change to this algorithm silently re-buckets
 * every live user — the worst kind of flag incident. `BucketingVectorsTest` pins both
 * canonical MurmurHash3 reference vectors (proving this *is* MurmurHash3) and the
 * derived bucket values. Never change the algorithm; if you must, bump the salt.
 */
final class Bucketing
{
    public const BUCKETS = 10_000;

    private const C1 = 0xcc9e2d51;
    private const C2 = 0x1b873593;

    /** A bucket in `[0, BUCKETS)` for (salt, key). Same inputs → same bucket, forever. */
    public static function bucket(string $salt, string $key): int
    {
        return self::murmur3_32($salt . "\x00" . $key) % self::BUCKETS;
    }

    /** The bucket as a float in `[0, 1)` — for weighted/continuous math (e.g. ramps). */
    public static function bucketFloat(string $salt, string $key): float
    {
        return self::bucket($salt, $key) / self::BUCKETS;
    }

    /** MurmurHash3 x86_32. Returns an unsigned 32-bit integer. */
    public static function murmur3_32(string $key, int $seed = 0): int
    {
        $length  = strlen($key);
        $h1      = $seed & 0xffffffff;
        $rounded = $length & ~3;

        for ($i = 0; $i < $rounded; $i += 4) {
            $k1 = ord($key[$i])
                | (ord($key[$i + 1]) << 8)
                | (ord($key[$i + 2]) << 16)
                | (ord($key[$i + 3]) << 24);

            $k1 = self::mul32($k1, self::C1);
            $k1 = self::rotl32($k1, 15);
            $k1 = self::mul32($k1, self::C2);

            $h1 ^= $k1;
            $h1 = self::rotl32($h1, 13);
            $h1 = (self::mul32($h1, 5) + 0xe6546b64) & 0xffffffff;
        }

        // Tail
        $k1   = 0;
        $tail = $length & 3;
        if ($tail >= 3) {
            $k1 ^= ord($key[$rounded + 2]) << 16;
        }
        if ($tail >= 2) {
            $k1 ^= ord($key[$rounded + 1]) << 8;
        }
        if ($tail >= 1) {
            $k1 ^= ord($key[$rounded]);
            $k1 = self::mul32($k1, self::C1);
            $k1 = self::rotl32($k1, 15);
            $k1 = self::mul32($k1, self::C2);
            $h1 ^= $k1;
        }

        // Finalization
        $h1 ^= $length;
        $h1 = self::fmix32($h1);

        return $h1 & 0xffffffff;
    }

    /** 32-bit multiply with wraparound (avoids float overflow on 64-bit PHP). */
    private static function mul32(int $a, int $b): int
    {
        $a &= 0xffffffff;
        $b &= 0xffffffff;

        $aLow  = $a & 0xffff;
        $aHigh = ($a >> 16) & 0xffff;

        return (($aLow * $b) + ((($aHigh * $b) & 0xffff) << 16)) & 0xffffffff;
    }

    private static function rotl32(int $x, int $r): int
    {
        $x &= 0xffffffff;

        return (($x << $r) | ($x >> (32 - $r))) & 0xffffffff;
    }

    private static function fmix32(int $h): int
    {
        $h &= 0xffffffff;
        $h ^= $h >> 16;
        $h = self::mul32($h, 0x85ebca6b);
        $h ^= $h >> 13;
        $h = self::mul32($h, 0xc2b2ae35);
        $h ^= $h >> 16;

        return $h & 0xffffffff;
    }
}
