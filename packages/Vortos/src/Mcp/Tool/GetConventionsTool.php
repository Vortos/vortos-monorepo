<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetConventionsTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_conventions';
    }

    public function description(): string
    {
        return 'Returns all Vortos non-negotiable architecture rules and naming conventions. Always call this first when working on a Vortos project.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments): string
    {
        $data = require __DIR__ . '/../Data/conventions.php';

        $output = "# Vortos Conventions\n\n";

        $output .= "## Golden Rules (Non-Negotiable)\n\n";
        foreach ($data['golden_rules'] as $i => $rule) {
            $output .= ($i + 1) . ". {$rule}\n";
        }

        $output .= "\n## Naming Conventions\n\n";
        $output .= "| Artifact | Convention |\n|---|---|\n";
        foreach ($data['naming'] as $artifact => $convention) {
            $label = str_replace('_', ' ', ucfirst($artifact));
            $output .= "| {$label} | {$convention} |\n";
        }

        $output .= "\n## Package Registration Order\n\n";
        foreach ($data['package_registration_order'] as $order => $package) {
            $output .= "{$order}. {$package}\n";
        }

        return $output;
    }
}
