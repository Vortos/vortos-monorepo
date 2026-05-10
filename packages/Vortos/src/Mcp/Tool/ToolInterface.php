<?php

declare(strict_types=1);

namespace Vortos\Mcp\Tool;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** JSON Schema object describing the tool's input parameters. */
    public function inputSchema(): array;

    /** Execute the tool and return a markdown string for the AI to read. */
    public function execute(array $arguments): string;
}
