<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Architecture;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * §12.4 / §6.1 — the three independent failure detectors (in-app probes, the
 * dead-man heartbeat, the external synthetic prober) must not share a transport.
 * Concretely: no class under `Uptime/Driver/` may be referenced from the in-app
 * `Probe/` graph or from `Observability/Sink/` — that would re-couple the synthetic
 * detector's failure mode to the very thing it exists to be independent of.
 */
final class DetectorIndependenceTest extends TestCase
{
    public function testNoUptimeDriverLeaksIntoTheProbeGraph(): void
    {
        $this->assertNoReferenceToUptimeDriver(dirname(__DIR__, 2) . '/Probe');
    }

    public function testNoUptimeDriverLeaksIntoObservabilitySinks(): void
    {
        $sinkDir = dirname(__DIR__, 3) . '/Observability/Sink';

        if (!is_dir($sinkDir)) {
            self::markTestSkipped('vortos-observability not present in this build.');
        }

        $this->assertNoReferenceToUptimeDriver($sinkDir);
    }

    public function testNoUptimeDriverLeaksIntoTheMonitorOrchestrationLayer(): void
    {
        // MonitorTick polls the port (UptimeMonitorInterface), never a concrete driver.
        $this->assertNoReferenceToUptimeDriver(dirname(__DIR__, 2) . '/Monitor');
    }

    private function assertNoReferenceToUptimeDriver(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/Tests/')) {
                continue;
            }

            $content = file_get_contents($path);
            self::assertIsString($content);

            self::assertStringNotContainsString(
                'Health\\Uptime\\Driver',
                $content,
                sprintf(
                    '%s references the Uptime driver namespace — this would share a transport with the '
                    . 'synthetic detector, breaking the independence the three-detector model depends on.',
                    $path,
                ),
            );
        }
    }
}
