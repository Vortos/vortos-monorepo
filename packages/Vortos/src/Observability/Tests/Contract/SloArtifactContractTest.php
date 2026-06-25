<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Slo\BurnRatePolicy;
use Vortos\Observability\Slo\Slo;
use Vortos\Observability\Slo\SloArtifactRenderer;
use Vortos\Observability\Slo\SloWindow;

/**
 * Pins the SLO artifact output format consumed by Block 17 (Alerts).
 * Any structural change here must be coordinated with SloBurnAlertSource.
 */
final class SloArtifactContractTest extends TestCase
{
    private function pinnedArtifact(): array
    {
        $slo = new Slo(
            name: 'api-availability',
            objective: 0.999,
            window: SloWindow::days(30),
            indicatorRef: 'http_request_success_ratio',
        );

        $policy = BurnRatePolicy::googleSreDefault();

        return (new SloArtifactRenderer())->render($slo, $policy);
    }

    public function test_structural_contract(): void
    {
        $artifact = $this->pinnedArtifact();

        self::assertArrayHasKey('name', $artifact);
        self::assertArrayHasKey('objective', $artifact);
        self::assertArrayHasKey('error_budget_fraction', $artifact);
        self::assertArrayHasKey('window_seconds', $artifact);
        self::assertArrayHasKey('indicator_ref', $artifact);
        self::assertArrayHasKey('burn_rate', $artifact);
        self::assertArrayHasKey('fast', $artifact['burn_rate']);
        self::assertArrayHasKey('slow', $artifact['burn_rate']);
        self::assertArrayHasKey('window_seconds', $artifact['burn_rate']['fast']);
        self::assertArrayHasKey('threshold', $artifact['burn_rate']['fast']);
        self::assertArrayHasKey('window_seconds', $artifact['burn_rate']['slow']);
        self::assertArrayHasKey('threshold', $artifact['burn_rate']['slow']);
    }

    public function test_pinned_vector(): void
    {
        self::assertSame([
            'name' => 'api-availability',
            'objective' => 0.999,
            'error_budget_fraction' => 1.0 - 0.999,
            'window_seconds' => 30 * 86400,
            'indicator_ref' => 'http_request_success_ratio',
            'burn_rate' => [
                'fast' => [
                    'window_seconds' => 3600,
                    'threshold' => 14.4,
                ],
                'slow' => [
                    'window_seconds' => 21600,
                    'threshold' => 6.0,
                ],
            ],
        ], $this->pinnedArtifact());
    }

    public function test_render_all_preserves_order(): void
    {
        $renderer = new SloArtifactRenderer();
        $policy = BurnRatePolicy::googleSreDefault();

        $sloA = new Slo('slo-a', 0.99, SloWindow::days(7), 'metric_a');
        $sloB = new Slo('slo-b', 0.995, SloWindow::days(30), 'metric_b');

        $artifacts = $renderer->renderAll([[$sloA, $policy], [$sloB, $policy]]);

        self::assertCount(2, $artifacts);
        self::assertSame('slo-a', $artifacts[0]['name']);
        self::assertSame('slo-b', $artifacts[1]['name']);
    }
}
