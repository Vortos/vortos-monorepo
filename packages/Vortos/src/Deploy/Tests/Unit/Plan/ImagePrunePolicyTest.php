<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Plan;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Plan\ImagePrunePolicy;

final class ImagePrunePolicyTest extends TestCase
{
    public function test_defaults_keep_active_and_previous(): void
    {
        $policy = new ImagePrunePolicy();

        $this->assertTrue($policy->enabled);
        $this->assertSame(2, $policy->keep);
        $this->assertSame('168h', $policy->builderCacheMaxAge);
    }

    public function test_disabled_factory(): void
    {
        $this->assertFalse(ImagePrunePolicy::disabled()->enabled);
    }

    public function test_keep_below_two_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImagePrunePolicy(keep: 1);
    }

    public function test_malformed_cache_age_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ImagePrunePolicy(builderCacheMaxAge: 'a week');
    }

    /** @dataProvider validAges */
    public function test_accepts_valid_cache_ages(string $age): void
    {
        $this->assertSame($age, (new ImagePrunePolicy(builderCacheMaxAge: $age))->builderCacheMaxAge);
    }

    /** @return iterable<array{string}> */
    public static function validAges(): iterable
    {
        yield ['30m'];
        yield ['24h'];
        yield ['168h'];
        yield ['7d'];
        yield ['90s'];
    }
}
