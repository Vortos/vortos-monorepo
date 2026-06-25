<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class WorkerDrainCleanArchTest extends TestCase
{
    public function test_worker_domain_does_not_depend_on_infra(): void
    {
        $workerDir = dirname(__DIR__, 2) . '/Worker';

        if (!is_dir($workerDir)) {
            $this->markTestSkipped('Worker/ directory does not exist yet.');
        }

        $forbiddenPatterns = [
            'Symfony\\Component\\DependencyInjection',
            'Vortos\\Deploy\\DependencyInjection',
            'Vortos\\Deploy\\Driver\\',
        ];

        $violations = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workerDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            foreach ($forbiddenPatterns as $pattern) {
                if (str_contains($code, $pattern)) {
                    $violations[] = basename($file->getPathname()) . ' depends on ' . $pattern;
                }
            }
        }

        $this->assertSame([], $violations, "Worker/ domain must not depend on infra:\n  - " . implode("\n  - ", $violations));
    }

    public function test_worker_domain_does_not_reference_supervisor(): void
    {
        $workerDir = dirname(__DIR__, 2) . '/Worker';

        if (!is_dir($workerDir)) {
            $this->markTestSkipped('Worker/ directory does not exist yet.');
        }

        $violations = [];

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workerDir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('/\bsupervisor\b/i', $code)) {
                $violations[] = basename($file->getPathname()) . ' references "supervisor"';
            }
        }

        $this->assertSame([], $violations, "Worker/ domain must not reference supervisor:\n  - " . implode("\n  - ", $violations));
    }
}
