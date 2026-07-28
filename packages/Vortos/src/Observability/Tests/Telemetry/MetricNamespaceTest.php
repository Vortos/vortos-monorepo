<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Telemetry;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\MetricNamespace;

final class MetricNamespaceTest extends TestCase
{
    public function test_prefixes_with_an_underscore_by_default(): void
    {
        self::assertSame('app_http_requests_total', MetricNamespace::of('app')->prefix('http_requests_total'));
    }

    public function test_prefixes_a_framework_metric_with_its_undecorated_name(): void
    {
        self::assertSame(
            'app_dlq_backlog_size',
            MetricNamespace::of('app')->forMetric(FrameworkMetric::DlqBacklogSize),
        );
    }

    public function test_supports_the_dot_separator_statsd_hierarchies_use(): void
    {
        self::assertSame('app.http_requests_total', MetricNamespace::of('app')->prefix('http_requests_total', '.'));
    }

    public function test_default_matches_the_framework_default(): void
    {
        self::assertSame('vortos', MetricNamespace::default()->value);
        self::assertSame(MetricNamespace::DEFAULT, MetricNamespace::default()->value);
    }

    #[DataProvider('illegalNamespaces')]
    public function test_rejects_a_namespace_that_is_not_a_legal_metric_name(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MetricNamespace::of($value);
    }

    /** @return iterable<string, array{string}> */
    public static function illegalNamespaces(): iterable
    {
        yield 'empty'          => [''];
        yield 'leading digit'  => ['1app'];
        yield 'hyphen'         => ['my-app'];
        yield 'dot'            => ['my.app'];
        yield 'space'          => ['my app'];
        yield 'trailing space' => ['app '];
    }

    public function test_equality_is_by_value(): void
    {
        self::assertTrue(MetricNamespace::of('app')->equals(MetricNamespace::of('app')));
        self::assertFalse(MetricNamespace::of('app')->equals(MetricNamespace::of('vortos')));
    }
}
