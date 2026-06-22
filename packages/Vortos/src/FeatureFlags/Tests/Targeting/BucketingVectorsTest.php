<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Targeting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Targeting\Bucketing;

/**
 * Pinned vectors. If any assertion here fails, the hash changed — which would silently
 * re-bucket every live user. The canonical MurmurHash3 vectors prove the implementation
 * is genuinely MurmurHash3 x86_32; the bucket vectors pin the derived rollout mapping.
 */
final class BucketingVectorsTest extends TestCase
{
    /**
     * Canonical MurmurHash3 x86_32 reference values (from the smhasher test suite and the
     * algorithm's reference implementation). These are not ours to change.
     *
     * @return array<string,array{string,int,int}>
     */
    public static function canonicalVectors(): array
    {
        return [
            'empty seed 0'        => ['', 0x00000000, 0x00000000],
            'empty seed 1'        => ['', 0x00000001, 0x514e28b7],
            'empty seed -1'       => ['', 0xffffffff, 0x81f16f39],
            'test'                => ['test', 0, 0xba6bd213],
            'hello world'         => ['Hello, world!', 0, 0xc0363e43],
            'quick brown fox'     => ['The quick brown fox jumps over the lazy dog', 0, 0x2e4ff723],
            'aaaa smhasher seed'  => ['aaaa', 0x9747b28c, 0x5a97808a],
            'a smhasher seed'     => ['a', 0x9747b28c, 0x7fa09ea6],
        ];
    }

    #[DataProvider('canonicalVectors')]
    public function test_is_canonical_murmur3(string $input, int $seed, int $expected): void
    {
        $this->assertSame($expected, Bucketing::murmur3_32($input, $seed));
    }

    public function test_pinned_bucket_values(): void
    {
        // If these move, a rollout's cohort membership moved. Treat as a breaking change.
        $this->assertSame(213, Bucketing::bucket('my-flag', 'user-1'));
        $this->assertSame(833, Bucketing::bucket('my-flag', 'user-2'));
        $this->assertSame(989, Bucketing::bucket('checkout', 'tenant-42'));
    }

    public function test_bucket_is_within_range(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $b = Bucketing::bucket('flag', "key-{$i}");
            $this->assertGreaterThanOrEqual(0, $b);
            $this->assertLessThan(Bucketing::BUCKETS, $b);
        }
    }

    public function test_distribution_is_uniform(): void
    {
        // 10% target over 20k keys should land within a tight tolerance — proves the
        // even distribution that crc32 % 100 lacked.
        $hits  = 0;
        $total = 20_000;
        for ($i = 0; $i < $total; $i++) {
            if (Bucketing::bucket('dist-flag', "user-{$i}") < 1000) { // 10% of 10000
                $hits++;
            }
        }

        $ratio = $hits / $total;
        $this->assertGreaterThan(0.09, $ratio);
        $this->assertLessThan(0.11, $ratio);
    }

    public function test_bucket_float_in_unit_interval(): void
    {
        $f = Bucketing::bucketFloat('flag', 'user-1');
        $this->assertGreaterThanOrEqual(0.0, $f);
        $this->assertLessThan(1.0, $f);
    }

    public function test_different_salts_decorrelate(): void
    {
        // The rollout salt and the variant salt must not produce identical buckets,
        // otherwise variant assignment would be biased by rollout membership.
        $same = 0;
        for ($i = 0; $i < 1000; $i++) {
            $key = "user-{$i}";
            if (Bucketing::bucket('flag', $key) === Bucketing::bucket("flag\x00variant", $key)) {
                $same++;
            }
        }
        // Collisions should be rare (~0.01% expected); assert well below 1%.
        $this->assertLessThan(10, $same);
    }
}
