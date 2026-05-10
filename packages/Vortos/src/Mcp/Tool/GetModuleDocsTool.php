<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetModuleDocsTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_module_docs';
    }

    public function description(): string
    {
        return 'Returns documentation for a specific Vortos module (or all modules if no module is specified). Covers what each module provides, its configuration options, and its console commands.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'module' => [
                    'type'        => 'string',
                    'description' => 'Module name (e.g. messaging, cqrs, auth, persistence, security). Omit to get all modules.',
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

        $modules = $filter !== null ? [$filter => $all[$filter]] : $all;
        $output  = "# Vortos Module Reference\n\n";

        foreach ($modules as $name => $module) {
            $output .= "## {$name}\n\n";
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
                $output .= "**Configuration options** (`config/{$name}.php`):\n\n";
                $output .= "| Option | Description |\n|---|---|\n";
                foreach ($module['config'] as $option => $desc) {
                    $output .= "| `{$option}` | {$desc} |\n";
                }
                $output .= "\n";
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
}
