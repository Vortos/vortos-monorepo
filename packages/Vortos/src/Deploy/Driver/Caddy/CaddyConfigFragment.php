<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Caddy;

use Vortos\Deploy\Cutover\DesiredRoute;

/**
 * POST /load replaces Caddy's entire active config document, including the admin
 * block — any field the posted document omits reverts to Caddy's built-in default
 * (localhost:2019), not "stays as previously configured". Every config this class
 * builds must therefore echo back the actual admin listen address, or the very first
 * cutover silently breaks all admin API access that isn't already on the default.
 */
final class CaddyConfigFragment
{
    public function __construct(
        private readonly string $adminListen = 'localhost:2019',
    ) {}

    /** @return array<string, mixed> */
    public function build(DesiredRoute $desired): array
    {
        $handler = $this->buildHandler($desired);

        return [
            'admin' => ['listen' => $this->adminListen],
            'apps' => [
                'http' => [
                    'servers' => [
                        'app' => [
                            'listen' => [':443'],
                            'routes' => [
                                [
                                    'handle' => [$handler],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Build a weighted handler when the route specifies a partial weight (canary
     * ramp). The complement color gets 100 - weight. When weight is 100 (or 0) the
     * result is a single-upstream handler (backward-compatible).
     *
     * @return array<string, mixed>
     */
    public function buildWeighted(DesiredRoute $desired, string $otherUpstreamDial): array
    {
        $handler = [
            'handler' => 'reverse_proxy',
            'load_balancing' => [
                'selection_policy' => ['policy' => 'weighted_round_robin'],
            ],
            'upstreams' => [
                [
                    'dial' => sprintf('%s:%d', $desired->upstream->host, $desired->upstream->port),
                    'weight' => $desired->weight,
                ],
                [
                    'dial' => $otherUpstreamDial,
                    'weight' => 100 - $desired->weight,
                ],
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

        return [
            'admin' => ['listen' => $this->adminListen],
            'apps' => [
                'http' => [
                    'servers' => [
                        'app' => [
                            'listen' => [':443'],
                            'routes' => [
                                ['handle' => [$handler]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function buildHandler(DesiredRoute $desired): array
    {
        return [
            'handler' => 'reverse_proxy',
            'upstreams' => [
                [
                    'dial' => sprintf(
                        '%s:%d',
                        $desired->upstream->host,
                        $desired->upstream->port,
                    ),
                ],
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
    }

    public function toJson(DesiredRoute $desired): string
    {
        return json_encode(
            $this->build($desired),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed> */
    public function fromJson(string $json): array
    {
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
