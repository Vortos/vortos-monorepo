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

        $configPath = $global
            ? $client['global_config']
            : $projectDir . '/' . $client['project_config'];

        $consolePath = $projectDir . '/bin/vortos';
        $entry       = [
            'type'    => 'stdio',
            'command' => 'php',
            'args'    => [$consolePath, 'vortos:mcp:serve'],
        ];

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
}
