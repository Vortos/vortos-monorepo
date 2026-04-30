<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

/**
 * Scans Vortos module packages for SQL migration stubs.
 *
 * Convention: each module ships raw SQL files at:
 *   packages/Vortos/src/{Module}/Resources/migrations/*.sql
 *
 * These stubs are templates — they are never executed directly.
 * vortos:migrate:publish converts them to Doctrine migration classes in migrations/.
 *
 * Additional scan paths can be registered at runtime via addScanPath().
 * Paths may be absolute or relative to the project root.
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
            [$this->projectDir . '/packages/Vortos/src/*/Resources/migrations/*.sql'],
            array_map(
                fn(string $p) => str_starts_with($p, '/') ? $p : $this->projectDir . '/' . $p,
                $this->additionalPatterns,
            ),
        );

        $stubs = [];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $absolutePath) {
                $relative = ltrim(str_replace($this->projectDir, '', $absolutePath), '/');
                $module   = $this->extractModuleName($relative);

                $stubs[] = [
                    'module'   => $module,
                    'filename' => basename($absolutePath),
                    'path'     => $absolutePath,
                    'relative' => $relative,
                ];
            }
        }

        usort($stubs, static fn(array $a, array $b) => strcmp($a['relative'], $b['relative']));

        return $stubs;
    }

    private function extractModuleName(string $relativePath): string
    {
        $parts = explode('/', $relativePath);

        foreach ($parts as $i => $part) {
            if ($part === 'src' && isset($parts[$i + 1])) {
                return $parts[$i + 1];
            }
        }

        return 'Unknown';
    }
}
