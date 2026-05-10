<?php

declare(strict_types=1);

namespace Vortos\Mcp\Client;

final class KnownClients
{
    /** @return array<string, array{name: string, project_config: string, global_config: string}> */
    public function all(): array
    {
        $home = $this->homeDir();

        return [
            'claude' => [
                'name'           => 'Claude Code',
                'project_config' => '.claude/settings.json',
                'global_config'  => $home . '/.claude/settings.json',
                'mcp_key'        => 'mcpServers',
            ],
            'cursor' => [
                'name'           => 'Cursor',
                'project_config' => '.cursor/mcp.json',
                'global_config'  => $home . '/.cursor/mcp.json',
                'mcp_key'        => 'mcpServers',
            ],
            'windsurf' => [
                'name'           => 'Windsurf',
                'project_config' => '.windsurf/mcp.json',
                'global_config'  => $home . '/.windsurf/mcp.json',
                'mcp_key'        => 'mcpServers',
            ],
        ];
    }

    public function get(string $client): ?array
    {
        return $this->all()[$client] ?? null;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->all());
    }

    private function homeDir(): string
    {
        return $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? '/root';
    }
}
