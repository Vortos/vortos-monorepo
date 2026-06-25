<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Architecture;

use PHPUnit\Framework\TestCase;

final class SupplyChainAgnosticismTest extends TestCase
{
    private const PROVIDER_NAMES = ['cosign', 'trivy', 'syft', 'cloudflare'];
    private const ALLOWED_DIRS = ['Driver', 'Resources', 'Tests', 'Integration/Pipeline', 'DependencyInjection'];

    public function test_provider_names_confined_to_allowed_dirs(): void
    {
        $root = dirname(__DIR__, 3) . '/SupplyChain';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace($root . '/', '', $file->getPathname());

            $inAllowedDir = false;
            foreach (self::ALLOWED_DIRS as $dir) {
                if (str_starts_with($relativePath, $dir . '/')) {
                    $inAllowedDir = true;
                    break;
                }
            }

            if ($inAllowedDir) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            foreach (self::PROVIDER_NAMES as $name) {
                if (preg_match('/\buse\s+[^;]*\b' . preg_quote($name, '/') . '\b/i', $contents) === 1) {
                    $violations[] = sprintf('%s imports a provider-named class containing "%s"', $relativePath, $name);
                }
                if (preg_match('/\bnew\s+[A-Z][A-Za-z]*' . preg_quote(ucfirst($name), '/') . '/', $contents) === 1) {
                    $violations[] = sprintf('%s instantiates a provider-named class containing "%s"', $relativePath, $name);
                }
            }
        }

        self::assertSame([], $violations, "Provider names found outside allowed directories:\n" . implode("\n", $violations));
    }
}
