<?php

declare(strict_types=1);

namespace Vortos\Search\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Search\DependencyInjection\VortosSearchConfig;
use Vortos\Search\Enum\SearchDriver;

final class VortosSearchConfigTest extends TestCase
{
    public function testDefaultsArePortableAndSafe(): void
    {
        $config = (new VortosSearchConfig())->toArray();

        self::assertSame(SearchDriver::Portable->value, $config['driver']);
        self::assertFalse($config['row_level_security']);
        self::assertSame(15, $config['cache_ttl_seconds']);
        self::assertSame('vortos.search', $config['consumer']);
    }

    public function testFluentOverrides(): void
    {
        $config = (new VortosSearchConfig())
            ->driver(SearchDriver::PostgresFts)
            ->rowLevelSecurity(true)
            ->cacheTtl('30 seconds')
            ->consumer('vortos.search.custom')
            ->toArray();

        self::assertSame(SearchDriver::PostgresFts->value, $config['driver']);
        self::assertTrue($config['row_level_security']);
        self::assertSame(30, $config['cache_ttl_seconds']);
        self::assertSame('vortos.search.custom', $config['consumer']);
    }

    public function testCacheTtlAcceptsBareSecondsAndZero(): void
    {
        self::assertSame(45, (new VortosSearchConfig())->cacheTtl(45)->toArray()['cache_ttl_seconds']);
        self::assertSame(0, (new VortosSearchConfig())->cacheTtl(0)->toArray()['cache_ttl_seconds']);
    }
}
