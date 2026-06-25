<?php

declare(strict_types=1);

namespace Vortos\Secrets\Tests\Unit\Preflight;

use PHPUnit\Framework\TestCase;
use Vortos\Secrets\Preflight\PreflightReport;
use Vortos\Secrets\Value\SecretKey;

final class PreflightReportTest extends TestCase
{
    public function test_satisfied_when_nothing_missing(): void
    {
        $report = new PreflightReport([SecretKey::fromString('a')], []);

        self::assertTrue($report->isSatisfied());
        self::assertSame('All required secrets are present.', $report->explain());
    }

    public function test_not_satisfied_when_something_missing(): void
    {
        $report = new PreflightReport([], [SecretKey::fromString('db-password')]);

        self::assertFalse($report->isSatisfied());
    }

    public function test_explain_names_every_missing_secret(): void
    {
        $report = new PreflightReport([], [
            SecretKey::fromString('db-password'),
            SecretKey::fromString('api-key'),
        ]);

        $explanation = $report->explain();

        self::assertStringContainsString('db-password', $explanation);
        self::assertStringContainsString('api-key', $explanation);
        self::assertStringContainsString('2', $explanation);
    }

    public function test_to_array_shape(): void
    {
        $report = new PreflightReport(
            [SecretKey::fromString('present-key')],
            [SecretKey::fromString('missing-key')],
        );

        self::assertSame([
            'satisfied' => false,
            'present' => ['present-key'],
            'missing' => ['missing-key'],
        ], $report->toArray());
    }
}
