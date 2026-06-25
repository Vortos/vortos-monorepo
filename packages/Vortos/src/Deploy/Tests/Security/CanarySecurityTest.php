<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Security;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Query\Driver\PrometheusMetricsQuery;
use Vortos\Observability\Query\MetricQuery;

final class CanarySecurityTest extends TestCase
{
    /** @dataProvider provideInjectionPayloads */
    public function test_promql_injection_cannot_escape_matcher(string $value): void
    {
        $q = MetricQuery::fromSloRef('metric', ['color' => $value]);
        $promql = $q->toPromQL();

        // Must start with metric{ and end with }
        self::assertStringStartsWith('metric{', $promql);
        self::assertStringEndsWith('}', $promql);

        // The inner part must be a valid color="<escaped_value>" — no breakout
        $inner = substr($promql, strlen('metric{'), -1);
        self::assertSame(1, preg_match('/^color=".*"$/s', $inner), sprintf(
            'Injection escaped: payload="%s", promql="%s"',
            addcslashes($value, "\0..\37"),
            $promql,
        ));
    }

    /** @return array<string, array{string}> */
    public static function provideInjectionPayloads(): array
    {
        return [
            'closing brace' => ['}'],
            'double quote' => ['"'],
            'backslash' => ['\\'],
            'newline' => ["\n"],
            'null byte' => ["\0"],
            'comment' => ['# comment'],
            'selector breakout' => ['blue"}[5m]//'],
            'nested label' => ['blue{foo="bar"}'],
            'unicode injection' => ['blue"'],
            'mixed' => ["blue\"\n}[5m]"],
        ];
    }

    public function test_ssrf_metadata_endpoint_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SSRF');

        new PrometheusMetricsQuery('https://169.254.169.254');
    }

    public function test_ssrf_plain_http_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');

        new PrometheusMetricsQuery('http://prometheus.example.com');
    }

    public function test_blinded_backend_returns_inconclusive_not_progress(): void
    {
        // A transport that always fails (blinded backend)
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://prometheus.example.com',
            transport: static function (): never {
                throw new \RuntimeException('network unreachable');
            },
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));

        // Empty result → sampleCount=0 → Inconclusive not Progress
        self::assertTrue($result->isEmpty());
        self::assertTrue(is_nan($result->value));
    }
}
