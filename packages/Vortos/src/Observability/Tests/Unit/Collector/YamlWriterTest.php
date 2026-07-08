<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\YamlWriter;

final class YamlWriterTest extends TestCase
{
    public function test_emits_document_marker(): void
    {
        $out = (new YamlWriter())->dump(['a' => 1]);

        self::assertStringStartsWith("---\n", $out);
    }

    public function test_nested_map(): void
    {
        $out = (new YamlWriter())->dump(['receivers' => ['otlp' => ['protocols' => ['grpc' => ['endpoint' => '127.0.0.1:4317']]]]]);

        self::assertStringContainsString('receivers:', $out);
        self::assertStringContainsString('endpoint: 127.0.0.1:4317', $out);
    }

    public function test_list_of_scalars(): void
    {
        $out = (new YamlWriter())->dump(['extensions' => ['file_storage/vortos']]);

        self::assertStringContainsString("- file_storage/vortos", $out);
    }

    public function test_list_of_maps(): void
    {
        $out = (new YamlWriter())->dump(['actions' => [['key' => 'user.id', 'action' => 'delete']]]);

        self::assertStringContainsString('- key: user.id', $out);
        self::assertStringContainsString('action: delete', $out);
    }

    public function test_quotes_env_placeholder(): void
    {
        $out = (new YamlWriter())->dump(['headers' => ['Authorization' => '${env:MY_HEADERS}']]);

        self::assertStringContainsString('${env:MY_HEADERS}', $out);
    }

    public function test_booleans_and_null(): void
    {
        $out = (new YamlWriter())->dump(['tls' => ['insecure' => false], 'compression' => null]);

        self::assertStringContainsString('insecure: false', $out);
        self::assertStringContainsString('compression: null', $out);
    }

    public function test_deterministic_output(): void
    {
        $data = ['b' => 1, 'a' => ['y' => 2, 'x' => 3]];
        $writer = new YamlWriter();

        self::assertSame($writer->dump($data), $writer->dump($data));
    }

    public function test_numeric_looking_string_is_quoted_to_preserve_type(): void
    {
        // A genuine int stays bare; a numeric-looking STRING must be quoted so it round-trips as a
        // string (e.g. docker_stats api_version) rather than a YAML float/int.
        $out = (new YamlWriter())->dump(['api_version' => '1.44', 'count' => 3]);

        self::assertStringContainsString('api_version: "1.44"', $out);
        self::assertStringContainsString('count: 3', $out);
        self::assertStringNotContainsString('api_version: 1.44', $out);
    }

    public function test_bool_and_null_looking_strings_are_quoted(): void
    {
        $out = (new YamlWriter())->dump(['a' => 'true', 'b' => 'null', 'c' => 'no']);

        self::assertStringContainsString('a: "true"', $out);
        self::assertStringContainsString('b: "null"', $out);
        self::assertStringContainsString('c: "no"', $out);
    }
}
