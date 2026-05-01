<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Vortos\Foundation\Module\ModulePathResolver;

/**
 * Scans installed vortos/* packages for SQL migration stubs.
 *
 * Uses ModulePathResolver to find Resources/migrations/*.sql files across all
 * installed vortos/* modules. Works in both monorepo and Packagist installs
 * without dual-path globs or deduplication — installed.json is the source of truth.
 *
 * Additional scan paths can be registered via addScanPath() for app-level stubs.
 */
final class ModuleStubScanner
{
    /** @var list<string> */
    private array $additionalPatterns = [];

    public function __construct(
        private readonly ModulePathResolver $resolver,
        private readonly string $projectDir,
    ) {}

    public function addScanPath(string $globPattern): void
    {
        $this->additionalPatterns[] = $globPattern;
    }

    /**
     * @return list<array{module: string, filename: string, path: string, relative: string}>
     */
    public function scan(): array
    {
        $stubs = [];
        $seen  = [];

        foreach ($this->resolver->findInModules('Resources/migrations/*.sql') as $absolutePath) {
            $real = realpath($absolutePath);
            if ($real) {
                $seen[$real] = true;
            }

            $relative = $this->toRelative($absolutePath);
            $stubs[]  = [
                'module'   => $this->extractModuleName($relative),
                'filename' => basename($absolutePath),
                'path'     => $absolutePath,
                'relative' => $relative,
            ];
        }

        foreach ($this->additionalPatterns as $pattern) {
            $fullPattern = str_starts_with($pattern, '/') ? $pattern : $this->projectDir . '/' . $pattern;

            foreach (glob($fullPattern) ?: [] as $absolutePath) {
                $real = realpath($absolutePath);
                if ($real && isset($seen[$real])) {
                    continue;
                }
                if ($real) {
                    $seen[$real] = true;
                }

                $relative = $this->toRelative($absolutePath);
                $stubs[]  = [
                    'module'   => $this->extractModuleName($relative),
                    'filename' => basename($absolutePath),
                    'path'     => $absolutePath,
                    'relative' => $relative,
                ];
            }
        }

        usort($stubs, static fn(array $a, array $b) => strcmp($a['filename'], $b['filename']));

        return $stubs;
    }

    private function toRelative(string $absolutePath): string
    {
        return ltrim(str_replace($this->projectDir, '', $absolutePath), '/');
    }

    private function extractModuleName(string $relativePath): string
    {
        $parts = explode('/', $relativePath);

        // Monorepo: packages/Vortos/src/{Module}/Resources/...
        foreach ($parts as $i => $part) {
            if ($part === 'src' && isset($parts[$i + 1])) {
                return $parts[$i + 1];
            }
        }

        // Packagist: vendor/vortos/vortos-{module}/Resources/...
        foreach ($parts as $i => $part) {
            if ($part === 'vortos' && isset($parts[$i + 1])) {
                return $parts[$i + 1];
            }
        }

        return 'Unknown';
    }
}
