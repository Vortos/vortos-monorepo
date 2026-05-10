<?php

declare(strict_types=1);

namespace Vortos\Mcp\Client;

final class ClientDetector
{
    public function __construct(
        private readonly string $projectDir,
        private readonly KnownClients $knownClients,
    ) {}

    /**
     * @return array<string, array{name: string, detected: bool, configured: bool, config_path: string, global_config_path: string}>
     */
    public function detect(): array
    {
        $results = [];

        foreach ($this->knownClients->all() as $id => $client) {
            $projectConfig = $this->projectDir . '/' . $client['project_config'];
            $globalConfig  = $client['global_config'];
            $detected      = file_exists($projectConfig)
                || file_exists(dirname($projectConfig))
                || file_exists($globalConfig)
                || file_exists(dirname($globalConfig));
            $configured    = ($client['format'] ?? 'json') === 'codex-toml'
                ? $this->hasCodexVortosEntry($globalConfig)
                : $this->hasVortosEntry($projectConfig);

            $results[$id] = [
                'name'               => $client['name'],
                'detected'           => $detected,
                'configured'         => $configured,
                'config_path'        => ($client['global_only'] ?? false) ? $globalConfig : $projectConfig,
                'global_config_path' => $globalConfig,
            ];
        }

        return $results;
    }

    public function hasVortosEntry(string $configPath): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }

        try {
            $data = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
            return isset($data['mcpServers']['vortos']);
        } catch (\JsonException) {
            return false;
        }
    }

    public function hasCodexVortosEntry(string $configPath): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }

        return preg_match('/(^|\n)\[mcp_servers\.vortos\]\n/', (string) file_get_contents($configPath)) === 1;
    }
}
