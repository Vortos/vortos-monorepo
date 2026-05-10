<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetArchitectureTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_architecture';
    }

    public function description(): string
    {
        return 'Returns Vortos architecture rules: layer responsibilities (Domain/Application/Infrastructure/Representation), CQRS + event flow, canonical file/directory structure, and transaction boundary rules.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments): string
    {
        $data   = require __DIR__ . '/../Data/architecture.php';
        $output = "# Vortos Architecture\n\n";

        $output .= "## Layers\n\n";
        foreach ($data['layers'] as $layer => $info) {
            $output .= "### {$layer}\n\n";
            $output .= "{$info['responsibility']}\n\n";
            $output .= "**Contains:** " . implode(', ', $info['contains']) . "\n\n";
            $output .= "**Rules:**\n";
            foreach ($info['rules'] as $rule) {
                $output .= "- {$rule}\n";
            }
            $output .= "\n";
        }

        $output .= "## CQRS + Event Flow\n\n";

        $output .= "### Command Flow\n\n";
        foreach ($data['cqrs_flow']['command_flow'] as $step) {
            $output .= "- {$step}\n";
        }

        $output .= "\n### Query Flow\n\n";
        foreach ($data['cqrs_flow']['query_flow'] as $step) {
            $output .= "- {$step}\n";
        }

        $output .= "\n### Projection Flow (Kafka → Read Model)\n\n";
        foreach ($data['cqrs_flow']['projection_flow'] as $step) {
            $output .= "- {$step}\n";
        }

        $output .= "\n## Canonical Directory Structure\n\n";
        $output .= "```\n" . $data['file_structure'] . "\n```\n\n";

        $output .= "## Transaction Boundary\n\n";
        $tx = $data['transaction_boundary'];
        $output .= "- **Owned by:** {$tx['owned_by']}\n";
        $output .= "- **Scope:** {$tx['scope']}\n";
        $output .= "- **Rule:** {$tx['rule']}\n";
        $output .= "- **Worker mode:** {$tx['worker_mode_note']}\n";

        return $output;
    }
}
