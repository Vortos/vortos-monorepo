<?php

declare(strict_types=1);

namespace Vortos\Mcp\Server;

use Vortos\Mcp\Tool\ToolInterface;

final class McpServer
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function __construct(private readonly StdioTransport $transport) {}

    public function addTool(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /** @return string[] */
    public function toolNames(): array
    {
        return array_keys($this->tools);
    }

    public function run(): void
    {
        while (true) {
            $message = $this->transport->read();
            if ($message === null) {
                continue;
            }

            $id     = $message['id'] ?? null;
            $method = $message['method'] ?? '';
            $params = $message['params'] ?? [];

            // Notifications have no id — no response required
            if ($id === null) {
                continue;
            }

            $response = match ($method) {
                'initialize'  => $this->handleInitialize($id),
                'tools/list'  => $this->handleToolsList($id),
                'tools/call'  => $this->handleToolsCall($id, $params),
                default       => Protocol::error($id, -32601, "Method not found: {$method}"),
            };

            $this->transport->write($response);
        }
    }

    private function handleInitialize(mixed $id): array
    {
        return Protocol::result($id, [
            'protocolVersion' => '2024-11-05',
            'serverInfo'      => ['name' => 'vortos-mcp', 'version' => '1.0.0'],
            'capabilities'    => ['tools' => new \stdClass()],
        ]);
    }

    private function handleToolsList(mixed $id): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name'        => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return Protocol::result($id, ['tools' => $tools]);
    }

    private function handleToolsCall(mixed $id, array $params): array
    {
        $name      = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            return Protocol::error($id, -32602, "Unknown tool: {$name}");
        }

        try {
            $text = $this->tools[$name]->execute($arguments);

            return Protocol::result($id, [
                'content' => [['type' => 'text', 'text' => $text]],
            ]);
        } catch (\Throwable $e) {
            return Protocol::result($id, [
                'content' => [['type' => 'text', 'text' => "Error executing tool: {$e->getMessage()}"]],
                'isError'  => true,
            ]);
        }
    }
}
