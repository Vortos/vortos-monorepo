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
}
