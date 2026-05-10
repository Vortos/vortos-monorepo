<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class ListProjectModulesTool implements ToolInterface
{
    public function __construct(private readonly string $projectDir) {}

    public function name(): string
    {
        return 'list_project_modules';
    }

    public function description(): string
    {
        return 'Lists the Vortos modules installed in this project by reading composer.lock. Shows which vortos/* packages are installed and their versions.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments): string
    {
        $lockFile = $this->projectDir . '/composer.lock';

        if (!file_exists($lockFile)) {
            return "composer.lock not found at {$lockFile}. Run `composer install` first.";
        }

        try {
            $lock     = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
            $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);
        } catch (\JsonException $e) {
            return "Failed to parse composer.lock: {$e->getMessage()}";
        }

        $vortos = array_filter($packages, static fn(array $p) => str_starts_with($p['name'], 'vortos/'));

        if (empty($vortos)) {
            return "No vortos/* packages found in composer.lock.";
        }

        $output = "# Installed Vortos Modules\n\n";
        $output .= "| Package | Version | Type |\n|---|---|---|\n";

        foreach ($vortos as $package) {
            $name    = $package['name'];
            $version = $package['version'] ?? 'unknown';
            $isDev   = in_array($package, $lock['packages-dev'] ?? [], true) ? 'dev' : 'prod';
            $output .= "| {$name} | {$version} | {$isDev} |\n";
        }

        return $output;
    }
}
