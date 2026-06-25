<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Sink;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Sink\ExporterConfig;

final class ExporterConfigTest extends TestCase
{
    public function test_to_array_is_canonically_sorted(): void
    {
        $config = ExporterConfig::create('otlp', [
            'zeta' => 1,
            'alpha' => 2,
            'nested' => ['y' => 'b', 'x' => 'a'],
        ]);

        $array = $config->toArray();

        self::assertSame(['alpha', 'nested', 'zeta'], array_keys($array['settings']));
        self::assertSame(['x', 'y'], array_keys($array['settings']['nested']));
    }

    public function test_is_deterministic(): void
    {
        $config = ExporterConfig::create('otlphttp', ['endpoint' => 'https://x:443', 'b' => true]);

        self::assertSame($config->toArray(), $config->toArray());
    }

    public function test_allows_null_and_scalar_and_one_level_map(): void
    {
        $config = ExporterConfig::create('otlp', [
            'endpoint' => 'https://x',
            'compression' => null,
            'tls' => ['insecure' => false],
        ]);

        self::assertSame('otlp', $config->toArray()['type']);
    }

    public function test_rejects_empty_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExporterConfig::create('', []);
    }

    public function test_rejects_non_string_setting_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentional bad input */
        ExporterConfig::create('otlp', [0 => 'x']);
    }

    public function test_rejects_two_level_nesting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExporterConfig::create('otlp', ['a' => ['b' => ['c' => 1]]]);
    }

    public function test_rejects_non_scalar_nested_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ExporterConfig::create('otlp', ['a' => ['b' => new \stdClass()]]);
    }
}
