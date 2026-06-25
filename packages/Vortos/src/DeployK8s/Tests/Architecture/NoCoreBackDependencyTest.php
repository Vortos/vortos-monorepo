<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class NoCoreBackDependencyTest extends TestCase
{
    public function test_core_deploy_never_imports_deploy_k8s(): void
    {
        $corePath = \dirname(__DIR__, 3) . '/Deploy';
        $this->assertDirectoryExists($corePath);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($corePath, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $violations = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            if (str_contains($contents, 'Vortos\\DeployK8s')) {
                $violations[] = $file->getPathname();
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "Core Deploy package must never import DeployK8s. Violations:\n%s",
                implode("\n", $violations),
            ),
        );
    }
}
