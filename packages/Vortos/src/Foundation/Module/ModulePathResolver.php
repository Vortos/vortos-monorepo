<?php

declare(strict_types=1);

namespace Vortos\Foundation\Module;

/**
 * Resolves file paths within installed vortos/* module directories.
 *
 * Uses vendor/composer/installed.json as the source of truth for where each
 * package is installed. Works identically in monorepo (symlinked vendor dirs)
 * and Packagist installs — no dual-path glob, no deduplication needed.
 *
 * Any scanner that reads from module directories (stubs, migrations, config, etc.)
 * should use this instead of globbing packages/ or vendor/ directly.
 *
 * Usage:
 *   $resolver->findInModules('Resources/stubs/entity.stub')
 *   $resolver->findInModules('Resources/migrations/*.sql')
 */
final class ModulePathResolver
{
    /** @var list<string>|null */
    private ?array $roots = null;

    public function __construct(private readonly string $projectDir) {}

    /**
     * Find files matching a glob pattern within all installed vortos/* module roots.
     *
     * @param string $relativeGlob Path relative to the module root, e.g. 'Resources/stubs/entity.stub'
     * @return list<string> Absolute paths of matched files
     */
    public function findInModules(string $relativeGlob): array
    {
        $results = [];

        foreach ($this->resolveModuleRoots() as $root) {
            foreach (glob($root . '/' . $relativeGlob) ?: [] as $path) {
                $results[] = $path;
            }
        }

        return $results;
    }

    /**
     * @return list<string> Absolute paths to all installed vortos/* module roots
     */
    private function resolveModuleRoots(): array
    {
        if ($this->roots !== null) {
            return $this->roots;
        }

        $installedJson = $this->projectDir . '/vendor/composer/installed.json';

        if (!file_exists($installedJson)) {
            return $this->roots = [];
        }

        $installed          = json_decode(file_get_contents($installedJson), true);
        $vendorComposerDir  = $this->projectDir . '/vendor/composer';
        $roots              = [];

        foreach ($installed['packages'] ?? $installed as $pkg) {
            if (!str_starts_with($pkg['name'] ?? '', 'vortos/')) {
                continue;
            }

            $installPath = $pkg['install-path'] ?? null;

            if ($installPath === null) {
                continue;
            }

            $abs = realpath($vendorComposerDir . '/' . $installPath);

            if ($abs !== false) {
                $roots[] = $abs;
            }
        }

        return $this->roots = $roots;
    }
}
