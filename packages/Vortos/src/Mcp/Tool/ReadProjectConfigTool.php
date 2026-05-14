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
            return "No config/ directory found at {$configDir}. Run `php bin/console vortos:config:publish` to publish config stubs.";
        }

        $filter = $arguments['file'] ?? null;

        if ($filter !== null) {
            if (str_contains($filter, "\0")) {
                return 'Invalid config file name.';
            }

            $resolved = realpath($configDir . '/' . ltrim($filter, '/') . '.php');
            $base     = realpath($configDir);

            if ($resolved === false || $base === false || !str_starts_with($resolved, $base . DIRECTORY_SEPARATOR)) {
                return "Config file not found: config/{$filter}.php";
            }

            $files = [$resolved];
        } else {
            $files = glob($configDir . '/*.php') ?: [];
            sort($files);
        }

        if (empty($files)) {
            return "No config files found in config/. Run `php bin/console vortos:config:publish` to publish config stubs.";
        }

        $output = "# Project Configuration (keys and value types only — values redacted)\n\n";

        foreach ($files as $file) {
            $name = basename($file, '.php');
            // phpcs:ignore
            $data = require $file;
            if (!is_array($data)) {
                $output .= "## config/{$name}.php\n\n(not a config array)\n\n";
                continue;
            }
            $output .= "## config/{$name}.php\n\n```\n" . $this->renderStructure($data) . "```\n\n";
        }

        return $output;
    }

    private function renderStructure(mixed $value, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);

        if (!is_array($value)) {
            return gettype($value) . "\n";
        }

        $out = '';
        foreach ($value as $k => $v) {
            $out .= $indent . $k . ': ';
            if (is_array($v)) {
                $out .= "\n" . $this->renderStructure($v, $depth + 1);
            } else {
                $out .= gettype($v) . "\n";
            }
        }
        return $out;
    }
}
