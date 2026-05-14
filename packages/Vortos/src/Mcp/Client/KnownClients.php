<?php

declare(strict_types=1);

namespace Vortos\Mcp\Client;

final class KnownClients
{
    /**
     * @return array<string, array{
     *     name: string,
     *     project_config: string,
     *     global_config: string,
     *     mcp_key: string,
     *     format?: 'json'|'codex-toml',
     *     global_only?: bool
     * }>
     */
    public function all(): array
    {
        $home = $this->homeDir();

        return [
            'codex' => [
                'name'           => 'Codex',
                'project_config' => '.codex/config.toml',
                'global_config'  => $home . '/.codex/config.toml',
                'mcp_key'        => 'mcp_servers',
                'format'         => 'codex-toml',
                'global_only'    => true,
            ],
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
        $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? null;

        if ($home === null) {
            throw new \RuntimeException(
                'Cannot determine home directory: neither HOME nor USERPROFILE is set.'
            );
        }

        if ($home === '/root') {
            trigger_error(
                'MCP config paths are being resolved under /root — running as root is not recommended.',
                E_USER_WARNING
            );
        }

        return $home;
    }
}
