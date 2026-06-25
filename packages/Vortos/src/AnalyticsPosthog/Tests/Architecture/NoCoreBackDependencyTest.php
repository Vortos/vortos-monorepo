<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Dependency direction is `driver -> core` only (§14.4): core must never import this
 * split package. Proves the split adds zero lines to core (§10.7 "no core drift").
 */
final class NoCoreBackDependencyTest extends TestCase
{
    public function test_analytics_core_never_imports_analytics_posthog(): void
    {
        $coreDir = dirname(__DIR__, 3) . '/Analytics';
        $this->assertDirectoryExists($coreDir);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($coreDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (str_contains($code, 'Vortos\\AnalyticsPosthog')) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame([], $violations, "Core must never import AnalyticsPosthog:\n  - " . implode("\n  - ", $violations));
    }
}
