<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Severity;

/**
 * Pinned golden vector for the default routing matrix (§6 Contract layer) — proves
 * the default contract (info/warn → chat, critical → page + chat mirror) never
 * silently drifts.
 */
final class RoutingMatrixContractTest extends TestCase
{
    private const GOLDEN_VECTOR = [
        'info' => ['eng-chat'],
        'warning' => ['eng-chat'],
        'critical' => ['oncall-page', 'eng-chat'],
    ];

    public function test_default_matrix_matches_pinned_golden_vector(): void
    {
        $matrix = RoutingMatrix::default();

        foreach (self::GOLDEN_VECTOR as $severityValue => $expectedChannels) {
            $severity = Severity::from($severityValue);
            self::assertSame(
                $expectedChannels,
                $matrix->channelsFor($severity, AlertSource::Health, null),
                "routing for severity '{$severityValue}' drifted from the pinned golden vector",
            );
        }
    }
}
