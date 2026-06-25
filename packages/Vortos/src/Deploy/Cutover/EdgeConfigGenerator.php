<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

final class EdgeConfigGenerator
{
    /** @return array<string, mixed> */
    public function generateCaddyConfig(string $domain): array
    {
        return [
            'admin' => [
                'listen' => 'localhost:2019',
                'enforce_origin' => false,
            ],
            'apps' => [
                'http' => [
                    'servers' => [
                        'app' => [
                            'listen' => [':443'],
                            'routes' => [
                                [
                                    'match' => [
                                        ['host' => [$domain]],
                                    ],
                                    'handle' => [
                                        [
                                            'handler' => 'reverse_proxy',
                                            'upstreams' => [
                                                ['dial' => 'app-blue:8081'],
                                            ],
                                            'health_checks' => [
                                                'active' => [
                                                    'uri' => '/health/ready',
                                                    'interval' => '10s',
                                                    'timeout' => '5s',
                                                ],
                                            ],
                                            'flush_interval' => -1,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'tls' => [
                    'automation' => [
                        'policies' => [
                            ['subjects' => [$domain]],
                        ],
                    ],
                ],
            ],
            'storage' => [
                'module' => 'file_system',
                'root' => '/data/caddy',
            ],
        ];
    }

    public function generateCaddyConfigJson(string $domain): string
    {
        return json_encode(
            $this->generateCaddyConfig($domain),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * Weighted Caddy config for canary ramp. When one side is 100/0 or 0/100 the
     * result is identical to generateCaddyConfig() (backward-compatible single upstream).
     */
    public function generateWeightedCaddyConfig(string $domain, int $blueWeight, int $greenWeight): array
    {
        if ($blueWeight < 0 || $blueWeight > 100 || $greenWeight < 0 || $greenWeight > 100) {
            throw new \InvalidArgumentException(sprintf(
                'Weights must be 0-100, got blue=%d green=%d.',
                $blueWeight,
                $greenWeight,
            ));
        }

        if ($blueWeight === 100 || $greenWeight === 0) {
            return $this->generateCaddyConfig($domain);
        }

        if ($greenWeight === 100 || $blueWeight === 0) {
            $cfg = $this->generateCaddyConfig($domain);
            // Swap the single upstream to green
            $cfg['apps']['http']['servers']['app']['routes'][0]['handle'][0]['upstreams'] = [
                ['dial' => 'app-green:8081'],
            ];

            return $cfg;
        }

        $base = $this->generateCaddyConfig($domain);
        $base['apps']['http']['servers']['app']['routes'][0]['handle'][0] = [
            'handler' => 'reverse_proxy',
            'load_balancing' => [
                'selection_policy' => ['policy' => 'weighted_round_robin'],
            ],
            'upstreams' => [
                ['dial' => 'app-blue:8081', 'weight' => $blueWeight],
                ['dial' => 'app-green:8081', 'weight' => $greenWeight],
            ],
            'health_checks' => [
                'active' => [
                    'uri' => '/health/ready',
                    'interval' => '10s',
                    'timeout' => '5s',
                ],
            ],
            'flush_interval' => -1,
        ];

        return $base;
    }

    public function generateWeightedCaddyConfigJson(string $domain, int $blueWeight, int $greenWeight): string
    {
        return json_encode(
            $this->generateWeightedCaddyConfig($domain, $blueWeight, $greenWeight),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }

    public function generateEdgeComposeYaml(string $domain): string
    {
        return <<<YAML
        services:
          edge:
            image: caddy:2-alpine
            container_name: vortos-edge
            restart: unless-stopped
            ports:
              - "80:80"
              - "443:443"
              - "443:443/udp"
            volumes:
              - ./caddy-config.json:/etc/caddy/caddy.json:ro
              - caddy_data:/data
              - caddy_config:/config
              - upstream_config:/etc/caddy/upstream
            command: caddy run --config /etc/caddy/caddy.json
            networks:
              - vortos-net

        volumes:
          caddy_data:
          caddy_config:
          upstream_config:

        networks:
          vortos-net:
            external: true
        YAML;
    }
}
