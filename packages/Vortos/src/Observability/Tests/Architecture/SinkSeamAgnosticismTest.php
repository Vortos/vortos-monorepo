<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\OpsKit\Architecture\AgnosticismScanner;
use Vortos\OpsKit\Architecture\ProviderNameMatcher;

/**
 * The §13 #1 guarantee for the NEW telemetry seam: no backend/provider name may
 * appear (as a symbol — namespace, class, or reference) anywhere in the port/domain/
 * buffer/collector/heartbeat code. Provider names live ONLY in the Driver\ namespace.
 *
 * (The pre-existing template publisher under Service/ is intentionally vendor-aware —
 * it ships vendor dashboards — and is out of scope for this seam-level lint.)
 */
final class SinkSeamAgnosticismTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function pureNamespaces(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            'Sink' => [$root . '/Sink'],
            'Buffer' => [$root . '/Buffer'],
            'Collector' => [$root . '/Collector'],
            'Heartbeat' => [$root . '/Heartbeat'],
            // Block 16: also agnostic — provider names only in Driver\.
            'Audit' => [$root . '/Audit'],
            'Marker' => [$root . '/Marker'],
            'Slo' => [$root . '/Slo'],
        ];
    }

    #[DataProvider('pureNamespaces')]
    public function test_no_provider_name_leaks(string $path): void
    {
        $scanner = new AgnosticismScanner(ProviderNameMatcher::default(), ['Driver'], ['/Tests/']);
        $occurrences = $scanner->scan($path);

        self::assertSame(
            [],
            array_map(static fn ($o) => $o->describe(), $occurrences),
            "Provider names leaked outside Driver\\:\n  - "
            . implode("\n  - ", array_map(static fn ($o) => $o->describe(), $occurrences)),
        );
    }
}
