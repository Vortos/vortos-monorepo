<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class CutoverCleanArchTest extends TestCase
{
    public function test_cutover_domain_does_not_depend_on_caddy(): void
    {
        $cutoverDir = dirname(__DIR__, 2) . '/Cutover';
        if (!is_dir($cutoverDir)) {
            $this->markTestSkipped('Cutover/ does not exist yet.');
        }

        $violations = [];

        foreach ($this->phpFiles($cutoverDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($code, 'Vortos\\Deploy\\Driver\\Caddy')) {
                $violations[] = $basename . ' imports Caddy driver namespace';
            }

            if (preg_match('/\bcaddy\b/i', $code) && !str_contains($code, 'EdgeRouterCapability')) {
                // Allow the string 'caddy' only in comments/doc, not in functional code references
                // But for safety, just check namespace-level imports
                foreach (explode("\n", $code) as $line) {
                    if (str_starts_with(trim($line), 'use ') && str_contains(strtolower($line), 'caddy')) {
                        $violations[] = $basename . ' has a use statement referencing Caddy';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Cutover/ (domain/app) must not depend on Caddy driver:\n  - " . implode("\n  - ", $violations),
        );
    }

    public function test_cutover_domain_does_not_depend_on_di(): void
    {
        $cutoverDir = dirname(__DIR__, 2) . '/Cutover';
        if (!is_dir($cutoverDir)) {
            $this->markTestSkipped('Cutover/ does not exist yet.');
        }

        $violations = [];

        foreach ($this->phpFiles($cutoverDir) as $file) {
            $code = (string) file_get_contents($file);
            $basename = basename($file);

            if (str_contains($code, 'Symfony\\Component\\DependencyInjection')) {
                $violations[] = $basename . ' depends on Symfony DI';
            }
        }

        $this->assertSame([], $violations, 'Cutover/ must not depend on DependencyInjection');
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
