<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class K8sAgnosticismTest extends TestCase
{
    private const PROVIDER_STRINGS = ['kubernetes', 'kubectl', 'helm', 'k8s'];

    public function test_provider_strings_not_in_core_deploy(): void
    {
        $corePath = \dirname(__DIR__, 3) . '/Deploy';
        $this->assertDirectoryExists($corePath);

        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($corePath, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Skip test fixtures — they may legitimately reference provider names
            if (str_contains($file->getPathname(), '/Tests/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            $lower = strtolower($contents);
            foreach (self::PROVIDER_STRINGS as $provider) {
                if (str_contains($lower, $provider)) {
                    // Ignore occurrences in comments
                    $lines = explode("\n", $contents);
                    foreach ($lines as $lineNum => $line) {
                        $lineLower = strtolower($line);
                        $trimmed = ltrim($line);
                        if (str_contains($lineLower, $provider) && !str_starts_with($trimmed, '//') && !str_starts_with($trimmed, '*') && !str_starts_with($trimmed, '/*')) {
                            // Check if it's in a suggest section of composer.json-like content
                            if (str_contains($line, '"suggest"') || str_contains($line, 'vortos-deploy-k8s') || str_contains($line, 'vortos-deploy-ecs')) {
                                continue;
                            }
                            $violations[] = sprintf('%s:%d — contains "%s"', $file->getPathname(), $lineNum + 1, $provider);
                        }
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            sprintf(
                "Core Deploy must not contain k8s provider strings outside comments/suggest.\nViolations:\n%s",
                implode("\n", $violations),
            ),
        );
    }
}
