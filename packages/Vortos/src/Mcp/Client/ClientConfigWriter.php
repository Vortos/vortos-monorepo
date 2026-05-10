<?php

declare(strict_types=1);

namespace Vortos\Mcp\Client;

final class ClientConfigWriter
{
    public function __construct(private readonly KnownClients $knownClients) {}

    /**
     * Merge the vortos MCP server entry into the client's config file.
     * Creates the file and parent directories if they do not exist.
     * Never touches existing unrelated keys.
     *
     * @return array{path: string, action: 'created'|'updated'|'unchanged'}
     */
    public function write(string $clientId, string $projectDir, bool $global = false): array
    {
        $client = $this->knownClients->get($clientId);
        if ($client === null) {
            throw new \InvalidArgumentException("Unknown client: {$clientId}");
        }

        $configPath = ($global || ($client['global_only'] ?? false))
            ? $client['global_config']
            : $projectDir . '/' . $client['project_config'];

        $consolePath = $projectDir . '/bin/console';
        $entry       = [
            'type'    => 'stdio',
            'command' => 'php',
            'args'    => [$consolePath, 'vortos:mcp:serve'],
        ];

        if (($client['format'] ?? 'json') === 'codex-toml') {
            return $this->writeCodexToml($configPath, $entry);
        }

        $existing = [];
        if (file_exists($configPath)) {
            try {
                $existing = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR) ?? [];
            } catch (\JsonException) {
                $existing = [];
            }
        }

        $currentEntry = $existing['mcpServers']['vortos'] ?? null;
        if ($currentEntry === $entry) {
            return ['path' => $configPath, 'action' => 'unchanged'];
        }

        $action                              = file_exists($configPath) ? 'updated' : 'created';
        $existing['mcpServers']['vortos']    = $entry;

        $dir = dirname($configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $configPath,
            json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return ['path' => $configPath, 'action' => $action];
    }

    /**
     * @param array{command: string, args: string[]} $entry
     * @return array{path: string, action: 'created'|'updated'|'unchanged'}
     */
    private function writeCodexToml(string $configPath, array $entry): array
    {
        $block = sprintf(
            "[mcp_servers.vortos]\ncommand = %s\nargs = [%s]\n",
            $this->tomlString($entry['command']),
            implode(', ', array_map(fn(string $arg): string => $this->tomlString($arg), $entry['args'])),
        );

        $existing = file_exists($configPath) ? (string) file_get_contents($configPath) : '';
        if ($this->codexMcpBlock($existing) === $block) {
            return ['path' => $configPath, 'action' => 'unchanged'];
        }

        $updated = $this->withoutCodexMcpBlock($existing);
        $updated = rtrim($updated);
        $updated = ($updated === '' ? '' : $updated . "\n\n") . $block;

        $dir = dirname($configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($configPath, $updated);

        return ['path' => $configPath, 'action' => file_exists($configPath) && $existing !== '' ? 'updated' : 'created'];
    }

    private function codexMcpBlock(string $config): ?string
    {
        if (preg_match('/(^|\n)(\[mcp_servers\.vortos\]\n(?:[^\[]|\[(?!mcp_servers\.))*\n?)/', $config, $match) !== 1) {
            return null;
        }

        return $match[2];
    }

    private function withoutCodexMcpBlock(string $config): string
    {
        return (string) preg_replace('/(^|\n)\[mcp_servers\.vortos\]\n(?:[^\[]|\[(?!mcp_servers\.))*\n?/', '$1', $config);
    }

    private function tomlString(string $value): string
    {
        return '"' . str_replace(
            ["\\", "\"", "\n", "\r", "\t"],
            ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
            $value,
        ) . '"';
    }
}
