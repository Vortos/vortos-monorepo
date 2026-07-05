<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Runtime\RuntimeServiceSpec;

final class EdgeConfigGenerator
{
    /**
     * @param int $containerPort the internal port the color serves on — the single source of truth is
     *                           {@see RuntimeServiceSpec::$containerPort}, threaded here from
     *                           config/deploy.php so the edge dial can never drift from the compose
     *                           expose port (watch-list: was hardcoded 8081 vs the 8080 spec default).
     */
    public function __construct(
        private readonly int $containerPort = RuntimeServiceSpec::DEFAULT_CONTAINER_PORT,
    ) {}

    /** Build from the app's runtime spec so the edge dial port is the single source of truth. */
    public static function fromSpec(RuntimeServiceSpec $spec): self
    {
        return new self($spec->containerPort);
    }

    private function dial(string $color): string
    {
        return sprintf('app-%s:%d', $color, $this->containerPort);
    }

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
                                                ['dial' => $this->dial('blue')],
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
                ['dial' => $this->dial('green')],
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
                ['dial' => $this->dial('blue'), 'weight' => $blueWeight],
                ['dial' => $this->dial('green'), 'weight' => $greenWeight],
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
