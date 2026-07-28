<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Dashboard\FrameworkDashboardCatalog;
use Vortos\Observability\Dashboard\GrafanaDashboardBuilder;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\MetricNamespace;

final class GrafanaDashboardBuilderTest extends TestCase
{
    public function test_produces_an_importable_document(): void
    {
        $document = $this->build();

        self::assertSame('vortos-platform', $document['uid']);
        self::assertSame(39, $document['schemaVersion']);
        self::assertNotEmpty($document['panels']);
        self::assertIsString(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function test_every_panel_id_is_unique(): void
    {
        $ids = array_column($this->build()['panels'], 'id');

        self::assertSame($ids, array_unique($ids), 'Grafana silently drops panels that share an id.');
    }

    public function test_panels_never_overflow_the_24_column_grid(): void
    {
        foreach ($this->build()['panels'] as $panel) {
            self::assertLessThanOrEqual(
                24,
                $panel['gridPos']['x'] + $panel['gridPos']['w'],
                sprintf('Panel "%s" extends past the grid.', $panel['title']),
            );
        }
    }

    public function test_queries_are_generated_from_the_metric_enum_not_hand_typed_strings(): void
    {
        $joined = implode("\n", $this->expressions());

        // A rename of any of these in FrameworkMetric must break this test rather than silently
        // produce a dashboard of empty graphs.
        foreach ([
            FrameworkMetric::DlqBacklogSize,
            FrameworkMetric::MessagingConsumerLag,
            FrameworkMetric::MessagingConsumerPollCyclesTotal,
            FrameworkMetric::SupervisorProgramUp,
            FrameworkMetric::SupervisorProgramRestartsTotal,
            FrameworkMetric::BackupLastSuccessAgeSeconds,
        ] as $metric) {
            self::assertStringContainsString($metric->value, $joined);
        }
    }

    /**
     * The regression this whole type exists for: queries must carry the namespace the adapter
     * actually emits under, not the bare enum value. Generating 'dlq_backlog_size' against a
     * deployment that emits 'app_dlq_backlog_size' produces a well-formed dashboard where every
     * panel is empty — discovered mid-incident, which is exactly what dashboards-as-code was
     * supposed to prevent.
     */
    public function test_queries_carry_the_configured_metric_namespace(): void
    {
        $document = (new GrafanaDashboardBuilder())
            ->build('t', 'prom-uid', 'uid', MetricNamespace::of('app'));

        $joined = implode("\n", $this->expressionsOf($document));

        self::assertStringContainsString('app_dlq_backlog_size', $joined);
        self::assertStringContainsString('app_supervisor_program_up', $joined);
        self::assertStringContainsString('app_http_request_duration_ms_bucket', $joined);

        // No expression may reference a bare, unprefixed framework metric name.
        foreach ($this->expressionsOf($document) as $expr) {
            self::assertDoesNotMatchRegularExpression(
                '/(?<![a-z0-9_])' . preg_quote(FrameworkMetric::DlqBacklogSize->value, '/') . '/',
                $expr,
                'An unprefixed metric name leaked into a generated query.',
            );
        }
    }

    public function test_defaults_to_the_framework_namespace_when_none_is_given(): void
    {
        $joined = implode("\n", $this->expressions());

        self::assertStringContainsString('vortos_dlq_backlog_size', $joined);
    }

    public function test_refuses_a_namespace_when_a_catalog_was_already_injected(): void
    {
        $builder = new GrafanaDashboardBuilder(new FrameworkDashboardCatalog(MetricNamespace::of('one')));

        $this->expectException(\LogicException::class);

        $builder->build('t', 'prom-uid', 'uid', MetricNamespace::of('two'));
    }

    /** @return list<string> */
    private function expressions(): array
    {
        return $this->expressionsOf($this->build());
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    private function expressionsOf(array $document): array
    {
        $expressions = [];

        foreach ($document['panels'] as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                $expressions[] = $target['expr'];
            }
        }

        return $expressions;
    }

    public function test_every_section_renders_a_row_header(): void
    {
        $rows = array_values(array_filter(
            $this->build()['panels'],
            static fn (array $panel): bool => $panel['type'] === 'row',
        ));

        self::assertCount(count((new FrameworkDashboardCatalog())->sections()), $rows);
    }

    public function test_thresholds_are_ordered_green_then_warn_then_critical(): void
    {
        foreach ($this->build()['panels'] as $panel) {
            if ($panel['type'] === 'row') {
                continue;
            }

            $values = array_column($panel['fieldConfig']['defaults']['thresholds']['steps'], 'value');
            $numeric = array_values(array_filter($values, static fn ($v): bool => $v !== null));
            $sorted = $numeric;
            sort($sorted);

            self::assertSame($sorted, $numeric, sprintf('Panel "%s" has out-of-order thresholds.', $panel['title']));
        }
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        return (new GrafanaDashboardBuilder())->build('Vortos — Platform Observability', 'prom-uid', 'vortos-platform');
    }
}
