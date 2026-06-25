<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Roadmap §9: the three telemetry layers (product analytics / system observability
 * metrics / error tracking) must stay separate — conflating them is the exact
 * failure §9 warns against. Analytics may reuse the proven `Observability\Buffer\*`
 * spool *discipline* (an infrastructure utility, not a telemetry-layer concept), but
 * must never import the metrics or error-sink layers themselves; and those layers
 * must never import Analytics back.
 */
final class ThreeLayerSeparationTest extends TestCase
{
    public function test_analytics_does_not_import_metrics_layer(): void
    {
        $this->assertNoImportOf(dirname(__DIR__, 2), ['Vortos\\Metrics\\'], 'Analytics');
    }

    public function test_analytics_does_not_import_error_sink_layer(): void
    {
        $this->assertNoImportOf(dirname(__DIR__, 2), ['Vortos\\Observability\\Sink\\'], 'Analytics');
    }

    public function test_observability_sink_does_not_import_analytics(): void
    {
        $sinkDir = dirname(__DIR__, 3) . '/Observability/Sink';
        if (!is_dir($sinkDir)) {
            $this->markTestSkipped('Observability/Sink not present in this checkout.');
        }

        $this->assertNoImportOf($sinkDir, ['Vortos\\Analytics\\'], 'Observability\\Sink');
    }

    /** @param list<string> $forbidden */
    private function assertNoImportOf(string $dir, array $forbidden, string $label): void
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || str_contains($file->getPathname(), '/Tests/')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file->getPathname()) . " imports {$pattern}";
                }
            }
        }

        $this->assertSame([], $violations, "{$label} must not import:\n  - " . implode("\n  - ", $violations));
    }
}
