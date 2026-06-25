<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\Event\AlertSource;
use Vortos\Alerts\Routing\RoutingMatrix;
use Vortos\Alerts\Severity;

final class RoutingMatrixTest extends TestCase
{
    /** @return iterable<string, array{Severity, array<string>}> */
    public static function defaultMatrixCases(): iterable
    {
        yield 'info -> chat' => [Severity::Info, ['eng-chat']];
        yield 'warning -> chat' => [Severity::Warning, ['eng-chat']];
        yield 'critical -> page + chat mirror' => [Severity::Critical, ['oncall-page', 'eng-chat']];
    }

    #[DataProvider('defaultMatrixCases')]
    public function test_default_matrix_routes_every_severity_correctly(Severity $severity, array $expected): void
    {
        $matrix = RoutingMatrix::default();

        self::assertSame($expected, $matrix->channelsFor($severity, AlertSource::Health, null));
    }

    public function test_source_override_takes_precedence_over_default(): void
    {
        $matrix = new RoutingMatrix(
            bySeverity: [Severity::Warning->value => ['eng-chat']],
            sourceOverrides: [AlertSource::Backup->value => [Severity::Warning->value => ['backup-chat']]],
        );

        self::assertSame(['backup-chat'], $matrix->channelsFor(Severity::Warning, AlertSource::Backup, null));
        self::assertSame(['eng-chat'], $matrix->channelsFor(Severity::Warning, AlertSource::Health, null));
    }

    public function test_tenant_override_takes_precedence_over_source_and_default(): void
    {
        $matrix = new RoutingMatrix(
            bySeverity: [Severity::Critical->value => ['oncall-page']],
            sourceOverrides: [AlertSource::Backup->value => [Severity::Critical->value => ['backup-page']]],
            tenantOverrides: ['tenant-a' => [Severity::Critical->value => ['tenant-a-page']]],
        );

        self::assertSame(['tenant-a-page'], $matrix->channelsFor(Severity::Critical, AlertSource::Backup, 'tenant-a'));
        self::assertSame(['backup-page'], $matrix->channelsFor(Severity::Critical, AlertSource::Backup, 'tenant-b'));
    }

    public function test_rejects_severity_routed_to_zero_channels(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RoutingMatrix([Severity::Critical->value => []]);
    }
}
