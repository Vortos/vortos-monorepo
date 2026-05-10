<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

final class GetBestPracticesTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_best_practices';
    }

    public function description(): string
    {
        return 'Returns Vortos best practices for a specific topic: performance, security, testing, worker_mode, or kafka. Omit topic to get all.';
    }

    public function inputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'topic' => [
                    'type'        => 'string',
                    'description' => 'Topic: performance, security, testing, worker_mode, kafka. Omit for all.',
                    'enum'        => ['performance', 'security', 'testing', 'worker_mode', 'kafka'],
                ],
            ],
        ];
    }

    public function execute(array $arguments): string
    {
        $all    = require __DIR__ . '/../Data/bestpractices.php';
        $filter = $arguments['topic'] ?? null;

        if ($filter !== null && !isset($all[$filter])) {
            $available = implode(', ', array_keys($all));
            return "Unknown topic '{$filter}'. Available: {$available}";
        }

        $topics = $filter !== null ? [$filter => $all[$filter]] : $all;
        $output = "# Vortos Best Practices\n\n";

        foreach ($topics as $topic => $practices) {
            $label  = str_replace('_', ' ', ucfirst($topic));
            $output .= "## {$label}\n\n";
            foreach ($practices as $practice) {
                $output .= "- {$practice}\n";
            }
            $output .= "\n";
        }

        return $output;
    }
}
