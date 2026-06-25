<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Query\MetricQuery;

final class MetricQueryTest extends TestCase
{
    public function test_to_promql_no_matchers(): void
    {
        $q = MetricQuery::fromSloRef('http_requests_total');

        self::assertSame('http_requests_total', $q->toPromQL());
    }

    public function test_to_promql_with_matchers(): void
    {
        $q = MetricQuery::fromSloRef('http_requests_total', ['job' => 'app', 'color' => 'blue']);

        self::assertSame('http_requests_total{job="app",color="blue"}', $q->toPromQL());
    }

    public function test_label_value_double_quote_escaped(): void
    {
        $q = MetricQuery::fromSloRef('metric', ['label' => 'val"ue']);

        self::assertStringContainsString('label="val\\"ue"', $q->toPromQL());
    }

    public function test_label_value_backslash_escaped(): void
    {
        $q = MetricQuery::fromSloRef('metric', ['label' => 'val\\ue']);

        self::assertStringContainsString('label="val\\\\ue"', $q->toPromQL());
    }

    public function test_label_value_newline_escaped(): void
    {
        $q = MetricQuery::fromSloRef('metric', ['label' => "val\nue"]);

        self::assertStringContainsString('label="val\\nue"', $q->toPromQL());
    }

    /** @dataProvider provideInjectionPayloads */
    public function test_injection_metachar_cannot_escape_matcher(string $value): void
    {
        $q = MetricQuery::fromSloRef('metric', ['label' => $value]);
        $promql = $q->toPromQL();

        // The value must not break out of the label value quotes
        // Strategy: parse balanced braces and check structure
        self::assertStringStartsWith('metric{', $promql);
        self::assertStringEndsWith('}', $promql);

        // No unescaped closing brace or quote mid-value
        $inner = substr($promql, strlen('metric{'), -1);
        self::assertSame(1, preg_match('/^label=".*"$/s', $inner), sprintf(
            'Injection payload "%s" broke out of matcher. Full PromQL: %s',
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
            'carriage return' => ["\r"],
            'comment injection' => ['# injected'],
            'vector selector breakout' => ['blue"}[5m]#'],
            'nested selector' => ['blue{other="bad"}'],
        ];
    }

    public function test_invalid_label_name_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid label name');

        MetricQuery::fromSloRef('metric', ['123invalid' => 'value']);
    }

    public function test_label_name_with_hyphen_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MetricQuery::fromSloRef('metric', ['my-label' => 'value']);
    }

    public function test_empty_indicator_ref_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MetricQuery::fromSloRef('');
    }
}
