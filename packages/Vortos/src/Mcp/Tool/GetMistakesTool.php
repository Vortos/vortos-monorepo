<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetMistakesTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_mistakes';
    }

    public function description(): string
    {
        return 'Returns common Vortos antipatterns: what NOT to do, what to do instead, and why. Review this before writing any Vortos code.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function execute(array $arguments): string
    {
        $mistakes = require __DIR__ . '/../Data/mistakes.php';

        $output = "# Vortos Common Mistakes\n\n";

        foreach ($mistakes as $i => $entry) {
            $n       = $i + 1;
            $output .= "## {$n}. {$entry['wrong']}\n\n";
            $output .= "**Instead:** {$entry['right']}\n\n";
            $output .= "**Why:** {$entry['why']}\n\n";
        }

        return $output;
    }
}
