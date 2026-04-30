<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Scans Vortos module packages for SQL migration stubs.
 *
 * Scans two locations:
 *   1. Monorepo:   packages/Vortos/src/{Module}/Resources/migrations/*.sql
 *   2. Packagist:  vendor/vortos/{package}/Resources/migrations/*.sql
 *
 * Additional scan paths can be registered via addScanPath().
 */
final class ModuleStubScanner
{
    /** @var list<string> */
    private array $additionalPatterns = [];

    public function __construct(private readonly string $projectDir) {}

    public function addScanPath(string $globPattern): void
    {
        $this->additionalPatterns[] = $globPattern;
    }

    /**
     * @return list<array{module: string, filename: string, path: string, relative: string}>
     */
    public function scan(): array
    {
        $patterns = array_merge(
            [
                // Monorepo path
                $this->projectDir . '/packages/Vortos/src/*/Resources/migrations/*.sql',
                // Packagist install path
                $this->projectDir . '/vendor/vortos/*/Resources/migrations/*.sql',
            ],
            array_map(
                fn(string $p) => str_starts_with($p, '/') ? $p : $this->projectDir . '/' . $p,
                $this->additionalPatterns,
            ),
        );

        $stubs = [];
        $seen  = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $absolutePath) {
                $relative = ltrim(str_replace($this->projectDir, '', $absolutePath), '/');

                // Deduplicate — monorepo symlinks may resolve to same file as vendor path
                $real = realpath($absolutePath);
                if ($real && isset($seen[$real])) {
                    continue;
                }
                if ($real) {
                    $seen[$real] = true;
                }

                $module  = $this->extractModuleName($relative);
                $stubs[] = [
                    'module'   => $module,
                    'filename' => basename($absolutePath),
                    'path'     => $absolutePath,
                    'relative' => $relative,
                ];
            }
        }

        usort($stubs, static fn(array $a, array $b) => strcmp($a['filename'], $b['filename']));

        return $stubs;
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
