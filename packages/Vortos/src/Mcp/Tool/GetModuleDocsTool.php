<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetModuleDocsTool implements ToolInterface
{
    public function __construct(private readonly string $projectDir) {}

    public function name(): string
    {
        return 'get_module_docs';
    }

    public function description(): string
    {
        return 'Returns documentation for a specific Vortos module (or all modules if no module is specified). Covers what each module provides, its configuration options, and its console commands — and, for split packages, whether the package is actually installed in THIS project right now (read from composer.lock). Many modules (deploy, secrets, backup, alerts, analytics, pipeline, release, iac, health, ops_kit, ...) are opt-in: their classes and commands do not exist unless explicitly composer-required. Always check this before assuming a feature is available.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'module' => [
                    'type'        => 'string',
                    'description' => 'Module name (e.g. messaging, cqrs, auth, deploy, secrets, backup). Omit to get all modules.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $all    = require __DIR__ . '/../Data/modules.php';
        $filter = $arguments['module'] ?? null;

        if ($filter !== null && !isset($all[$filter])) {
            $available = implode(', ', array_keys($all));
            return "Unknown module '{$filter}'. Available modules: {$available}";
        }

        $modules  = $filter !== null ? [$filter => $all[$filter]] : $all;
        $installed = $this->installedVortosPackages();
        $output   = "# Vortos Module Reference\n\n";

        if ($installed === null) {
            $output .= "_Note: composer.lock not found at {$this->projectDir} — install status below could not be checked. Run \`composer install\` first, or use \`list_project_modules\` directly._\n\n";
        }

        foreach ($modules as $name => $module) {
            $output .= "## {$name}\n\n";

            $packageField = $module['package'] ?? null;
            if ($packageField !== null) {
                $output .= $this->renderInstallStatus($packageField, $installed);
            }

            $output .= "{$module['description']}\n\n";

            if (!empty($module['provides'])) {
                $output .= "**Provides:**\n\n";
                foreach ($module['provides'] as $key => $value) {
                    if (is_int($key)) {
                        $output .= "- {$value}\n";
                    } else {
                        $output .= "- `{$key}` — {$value}\n";
                    }
                }
                $output .= "\n";
            }

            if (!empty($module['config'])) {
                if (is_string($module['config'])) {
                    $output .= "**Configuration:** {$module['config']}\n\n";
                } else {
                    $output .= "**Configuration options** (`config/{$name}.php`):\n\n";
                    $output .= "| Option | Description |\n|---|---|\n";
                    foreach ($module['config'] as $option => $desc) {
                        $output .= "| `{$option}` | {$desc} |\n";
                    }
                    $output .= "\n";
                }
            }

            if (!empty($module['commands'])) {
                $output .= "**Console commands:**\n\n";
                foreach ($module['commands'] as $cmd => $desc) {
                    $output .= "- `{$cmd}` — {$desc}\n";
                }
                $output .= "\n";
            }
        }

        return $output;
    }

    /**
     * Reads composer.lock once and returns installed vortos/* package names
     * (lowercase, e.g. "vortos/vortos-deploy") mapped to their locked version.
     * Returns null if composer.lock is missing or unreadable — callers must
     * treat that as "unknown", never as "not installed".
     *
     * @return array<string, string>|null
     */
    private function installedVortosPackages(): ?array
    {
        $lockFile = $this->projectDir . '/composer.lock';

        if (!file_exists($lockFile)) {
            return null;
        }

        try {
            $lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        $vortos   = [];

        foreach ($packages as $package) {
            if (str_starts_with($package['name'], 'vortos/')) {
                $vortos[$package['name']] = $package['version'] ?? 'unknown';
            }
        }

        return $vortos;
    }

    /**
     * @param array<string, string>|null $installed
     */
    private function renderInstallStatus(string $packageField, ?array $installed): string
    {
        preg_match_all('/vortos\/vortos-[a-z0-9-]+/', $packageField, $matches);
        $referenced = array_unique($matches[0]);

        if ($referenced === [] || $installed === null) {
            return "**Package:** {$packageField}\n\n";
        }

        $lines = [];
        foreach ($referenced as $pkg) {
            $lines[] = isset($installed[$pkg])
                ? "✓ `{$pkg}` is installed ({$installed[$pkg]})"
                : "✗ `{$pkg}` is NOT installed — run `composer require {$pkg}` before using anything below";
        }

        return implode("\n", $lines) . "\n\n";
    }
}
