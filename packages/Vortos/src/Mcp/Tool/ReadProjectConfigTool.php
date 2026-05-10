<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class ReadProjectConfigTool implements ToolInterface
{
    public function __construct(private readonly string $projectDir) {}

    public function name(): string
    {
        return 'read_project_config';
    }

    public function description(): string
    {
        return 'Returns the contents of the project\'s config/*.php files — the published Vortos module configuration for this app. Use this to understand how this specific project is configured.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'file' => [
                    'type'        => 'string',
                    'description' => 'Specific config file to read (e.g. messaging, cqrs, auth). Omit to read all config files.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $configDir = $this->projectDir . '/config';

        if (!is_dir($configDir)) {
            return "No config/ directory found at {$configDir}. Run `php bin/vortos vortos:config:publish` to publish config stubs.";
        }

        $filter = $arguments['file'] ?? null;

        if ($filter !== null) {
            $path = $configDir . '/' . ltrim($filter, '/') . '.php';
            if (!file_exists($path)) {
                return "Config file not found: config/{$filter}.php";
            }
            $files = [$path];
        } else {
            $files = glob($configDir . '/*.php') ?: [];
            sort($files);
        }

        if (empty($files)) {
            return "No config files found in config/. Run `php bin/vortos vortos:config:publish` to publish config stubs.";
        }

        $output = "# Project Configuration\n\n";

        foreach ($files as $file) {
            $name    = basename($file, '.php');
            $content = file_get_contents($file);
            $output .= "## config/{$name}.php\n\n```php\n{$content}\n```\n\n";
        }

        return $output;
    }
}
