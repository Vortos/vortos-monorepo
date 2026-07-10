<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Sink;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Sink\OtlpProtocol;
use Vortos\Observability\Sink\SinkEndpoint;

final class SinkEndpointTest extends TestCase
{
    public function test_defaults_port_from_protocol(): void
    {
        $grpc = SinkEndpoint::create('collector.example.com', OtlpProtocol::Grpc);
        $http = SinkEndpoint::create('collector.example.com', OtlpProtocol::HttpProtobuf);

        self::assertSame(4317, $grpc->port);
        self::assertSame(4318, $http->port);
    }

    public function test_dsn_uses_https_when_tls_enabled(): void
    {
        $endpoint = SinkEndpoint::create('host.example.com', OtlpProtocol::HttpProtobuf, 443, true);

        // The scheme-default port (443 for https) is omitted — a public gateway is `https://host`.
        self::assertSame('https://host.example.com', $endpoint->dsn());
    }

    public function test_dsn_keeps_non_default_port(): void
    {
        $endpoint = SinkEndpoint::create('host.example.com', OtlpProtocol::HttpProtobuf, 4318, true);

        self::assertSame('https://host.example.com:4318', $endpoint->dsn());
    }

    public function test_dsn_uses_http_when_tls_disabled(): void
    {
        $endpoint = SinkEndpoint::create('host.example.com', OtlpProtocol::Grpc, 4317, false);

        self::assertSame('http://host.example.com:4317', $endpoint->dsn());
    }

    public function test_rejects_empty_host(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SinkEndpoint::create('   ', OtlpProtocol::Grpc);
    }

    #[DataProvider('invalidPorts')]
    public function test_rejects_out_of_range_port(int $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        SinkEndpoint::create('host', OtlpProtocol::Grpc, $port);
    }

    /** @return array<string, array{int}> */
    public static function invalidPorts(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'too-high' => [65536]];
    }

    public function test_rejects_empty_headers_ref(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SinkEndpoint::create('host', OtlpProtocol::Grpc, null, true, '');
    }

    public function test_to_array_round_trip_fields(): void
    {
        $endpoint = SinkEndpoint::create('h', OtlpProtocol::HttpProtobuf, 4318, true, 'MY_HEADERS', '/otlp');

        self::assertSame([
            'host' => 'h',
            'port' => 4318,
            'protocol' => 'http/protobuf',
            'tlsEnabled' => true,
            'headersEnvRef' => 'MY_HEADERS',
            'basePath' => '/otlp',
        ], $endpoint->toArray());
    }

    public function test_dsn_appends_base_path(): void
    {
        $endpoint = SinkEndpoint::create('gw.grafana.net', OtlpProtocol::HttpProtobuf, 443, true, null, '/otlp');

        self::assertSame('https://gw.grafana.net/otlp', $endpoint->dsn());
    }

    #[DataProvider('basePathVariants')]
    public function test_base_path_is_normalized(?string $input, ?string $expected): void
    {
        $endpoint = SinkEndpoint::create('h', OtlpProtocol::HttpProtobuf, 4318, true, null, $input);

        self::assertSame($expected, $endpoint->basePath);
    }

    /** @return array<string, array{?string, ?string}> */
    public static function basePathVariants(): array
    {
        return [
            'null stays null' => [null, null],
            'empty to null' => ['', null],
            'whitespace to null' => ['   ', null],
            'slashes only to null' => ['/', null],
            'bare word gets slash' => ['otlp', '/otlp'],
            'leading slash kept' => ['/otlp', '/otlp'],
            'trailing slash stripped' => ['otlp/', '/otlp'],
            'both slashes normalized' => ['/otlp/', '/otlp'],
            'nested path preserved' => ['api/otlp', '/api/otlp'],
        ];
    }
}
