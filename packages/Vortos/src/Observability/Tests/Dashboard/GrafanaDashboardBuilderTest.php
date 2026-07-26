<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Dashboard;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Dashboard\FrameworkDashboardCatalog;
use Vortos\Observability\Dashboard\GrafanaDashboardBuilder;
use Vortos\Observability\Telemetry\FrameworkMetric;

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
        $expressions = [];

        foreach ($this->build()['panels'] as $panel) {
            foreach ($panel['targets'] ?? [] as $target) {
                $expressions[] = $target['expr'];
            }
        }

        $joined = implode("\n", $expressions);

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
