<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Console;

use RuntimeException;

/**
 * Renders and writes the guided driver scaffold (roadmap §10.5).
 *
 * A tiny `{{Var}}` template renderer over the kit's own stubs — deliberately
 * independent of `vortos/vortos-make`'s GeneratorEngine, which writes into the App's
 * `src/`; a driver scaffold (especially a `--split` package) writes elsewhere.
 */
final class DriverScaffolder
{
    private readonly string $stubsDir;

    public function __construct(
        private readonly string $projectDir,
        ?string $stubsDir = null,
    ) {
        $this->stubsDir = $stubsDir ?? __DIR__ . '/../Resources/stubs';
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    /** @param array<string, string> $vars */
    public function render(string $stub, array $vars): string
    {
        if (str_contains($stub, "\0") || str_contains($stub, '/') || str_contains($stub, '..')) {
            throw new RuntimeException("Invalid stub name '{$stub}'.");
        }

        $path = $this->stubsDir . '/' . $stub . '.stub';
        if (!is_file($path)) {
            throw new RuntimeException("Stub '{$stub}' not found at {$path}.");
        }

        $template = (string) file_get_contents($path);
        foreach ($vars as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    /**
     * Writes $contents to $absolutePath unless it already exists.
     *
     * @return 'created'|'skipped'
     */
    public function write(string $absolutePath, string $contents): string
    {
        if (is_file($absolutePath)) {
            return 'skipped';
        }

        $dir = \dirname($absolutePath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}.");
        }

        file_put_contents($absolutePath, $contents, LOCK_EX);

        return 'created';
    }
}
