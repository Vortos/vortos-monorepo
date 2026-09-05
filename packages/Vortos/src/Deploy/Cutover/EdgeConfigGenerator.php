<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Runtime\ResourceLimits;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;

final class EdgeConfigGenerator
{
    /**
     * @param int $containerPort the internal port the color serves on — the single source of truth is
     *                           {@see RuntimeServiceSpec::$containerPort}, threaded here from
     *                           config/deploy.php so the edge dial can never drift from the compose
     *                           expose port (watch-list: was hardcoded 8081 vs the 8080 spec default).
     */
    /**
     * @param string $proxyTryDuration how long a request will keep retrying to find an available
     *                                  upstream before giving up with 502/503. This is the zero-downtime
     *                                  lever: during a fresh color's warmup the active health-check can
     *                                  briefly mark the sole upstream down; without a try window Caddy
     *                                  returns 503 to the client on the first empty-pool request. With
     *                                  it, the request is HELD and retried across the health-check
     *                                  interval, so a warmup blip never surfaces to the public.
     * @param string $proxyTryInterval how long to wait between those upstream-selection retries.
     * @param string $activeHealthInterval how often the edge actively probes the upstream's readiness.
     * @param string $activeHealthTimeout per-probe timeout for the active health-check.
     */
    public function __construct(
        private readonly int $containerPort = RuntimeServiceSpec::DEFAULT_CONTAINER_PORT,
        private readonly string $proxyTryDuration = '15s',
        private readonly string $proxyTryInterval = '250ms',
        private readonly string $activeHealthInterval = '10s',
        private readonly string $activeHealthTimeout = '5s',
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
        return $this->assemble($domain, 'localhost:2019', $this->singleUpstreamHandler($this->dial('blue')));
    }

    /**
     * Build the full edge config for a desired cutover route — the SINGLE source of truth for the
     * cutover config shape. Always carries the host matcher + tls.automation for the route's domain
     * (when set), so a Caddy /load PRESERVES the domain's certificate instead of clobbering it to
     * the internal default (GAP-D). The admin listen address is threaded in so the pushed config
     * echoes the edge's real admin bind — decoupled from the client's connect URL.
     *
     * @return array<string, mixed>
     */
    public function generateForRoute(DesiredRoute $desired, string $adminListen = 'localhost:2019'): array
    {
        return $this->assemble($desired->domain, $adminListen, $this->routeHandler($desired));
    }

    public function generateForRouteJson(DesiredRoute $desired, string $adminListen = 'localhost:2019'): string
    {
        return json_encode(
            $this->generateForRoute($desired, $adminListen),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The reverse-proxy handler for a desired route: a single upstream (the active color) at 100/0, or
     * a weighted pair (active + complement color) for a canary ramp. The complement dial uses the same
     * container port as the active upstream (both colors expose it), so it can never drift from a
     * hardcoded value.
     *
     * @return array<string, mixed>
     */
    private function routeHandler(DesiredRoute $desired): array
    {
        $activeDial = sprintf('%s:%d', $desired->upstream->host, $desired->upstream->port);

        if ($desired->weight >= 100 || $desired->weight <= 0) {
            return $this->singleUpstreamHandler($activeDial);
        }

        $complementDial = sprintf('app-%s:%d', $desired->activeColor->opposite()->value, $desired->upstream->port);

        return $this->weightedHandler(
            $activeDial,
            $desired->weight,
            $complementDial,
            100 - $desired->weight,
        );
    }

    /**
     * Assemble the full Caddy config document from a handler. When $domain is set the route gets a
     * host matcher and the config gets a tls.automation policy for it; a null domain builds an
     * internal / no-TLS edge.
     *
     * @param array<string, mixed> $handler
     * @return array<string, mixed>
     */
    private function assemble(?string $domain, string $adminListen, array $handler): array
    {
        $route = ['handle' => [$handler]];
        if ($domain !== null) {
            $route = [
                'match' => [['host' => [$domain]]],
                'handle' => [$handler],
            ];
        }

        $config = [
            'admin' => [
                'listen' => $adminListen,
                'enforce_origin' => false,
            ],
            'apps' => [
                'http' => [
                    'servers' => [
                        'app' => [
                            'listen' => [':443'],
                            'routes' => [$route],
                            // Enable per-server HTTP metrics (opt-in on Caddy 2.7+) so the drain
                            // observer can read caddy_http_requests_in_flight and so the edge is
                            // scrapeable. Empty object == enabled; encoded as "metrics":{}.
                            'metrics' => new \stdClass(),
                        ],
                    ],
                ],
            ],
            'storage' => [
                'module' => 'file_system',
                'root' => '/data/caddy',
            ],
        ];

        if ($domain !== null) {
            $config['apps']['tls'] = [
                'automation' => [
                    'policies' => [
                        ['subjects' => [$domain]],
                    ],
                ],
            ];
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private function singleUpstreamHandler(string $dial): array
    {
        return [
            'handler' => 'reverse_proxy',
            // Hold-and-retry so a warmup blip on the (single) upstream never surfaces as a public 503:
            // if the active health-check has momentarily pulled the color out of the pool, the request
            // waits up to try_duration for it to return instead of failing on an empty pool.
            'load_balancing' => $this->loadBalancing(),
            'upstreams' => [
                ['dial' => $dial],
            ],
            'health_checks' => $this->healthChecks(),
            'flush_interval' => -1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function weightedHandler(string $dialA, int $weightA, string $dialB, int $weightB): array
    {
        return [
            'handler' => 'reverse_proxy',
            'load_balancing' => [
                'selection_policy' => ['policy' => 'weighted_round_robin'],
            ] + $this->loadBalancing(),
            'upstreams' => [
                ['dial' => $dialA, 'weight' => $weightA],
                ['dial' => $dialB, 'weight' => $weightB],
            ],
            'health_checks' => $this->healthChecks(),
            'flush_interval' => -1,
        ];
    }

    /**
     * The hold-and-retry policy shared by every generated proxy handler. try_duration is the
     * zero-downtime guarantee: a request that finds no healthy upstream (e.g. the fresh color is
     * mid-warmup and the active check has it briefly marked down) is retried for this long rather
     * than returning 503 to the client.
     *
     * @return array<string, string>
     */
    private function loadBalancing(): array
    {
        return [
            'try_duration' => $this->proxyTryDuration,
            'try_interval' => $this->proxyTryInterval,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function healthChecks(): array
    {
        return [
            'active' => [
                'uri' => '/health/ready',
                'interval' => $this->activeHealthInterval,
                'timeout' => $this->activeHealthTimeout,
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
            return $this->assemble($domain, 'localhost:2019', $this->singleUpstreamHandler($this->dial('blue')));
        }

        if ($greenWeight === 100 || $blueWeight === 0) {
            return $this->assemble($domain, 'localhost:2019', $this->singleUpstreamHandler($this->dial('green')));
        }

        return $this->assemble(
            $domain,
            'localhost:2019',
            $this->weightedHandler($this->dial('blue'), $blueWeight, $this->dial('green'), $greenWeight),
        );
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
        // The edge's boot config lives on a HOST bind-mount (EDGE_CONFIG_DIR, default
        // /opt/vortos/edge/config), NOT an ephemeral named volume. Two writers keep it durable and
        // current, so the edge boots the CURRENT route from disk after ANY restart — including a bare
        // Docker daemon restart, which restarts the caddy container directly per its restart policy
        // and never re-runs edge-init (depends_on gates "compose up" only):
        //   • edge-init (this app image; caddy has no PHP) renders the config from the durable state
        //     store on "compose up", and on genuine first boot falls back to the scaffolded bootstrap
        //     so the edge can serve HTTPS and accept the first cutover's /load.
        //   • every cutover writes the exact rendered config straight to EDGE_CONFIG_DIR/caddy.json
        //     over SSH (CaddyEdgeRouter + MountedConfigWriter), so the on-disk file always matches the
        //     live route without waiting for edge-init to re-run.
        // The bind-mount is the DIRECTORY (not a single file) so the atomic temp+rename write survives
        // the inode swap. TLS/ACME material stays on the durable caddy_data volume so a cold boot never
        // re-issues certificates.
        //
        // The edge terminates EVERY client connection, so it is the container most exposed to the
        // daemon's stock soft 'nofile' of 1024 — at roughly two descriptors per client (downstream
        // socket plus upstream dial) that ceiling is reached a few hundred clients in, and it presents
        // as refused connections with idle CPU rather than as anything resembling load. The app colors
        // get the same limit from RuntimeServiceSpec; the constant is shared so the two cannot drift.
        // edge-init is deliberately left alone: it is a short-lived one-shot that opens no sockets.
        $nofile = ResourceLimits::DEFAULT_NOFILE;

        return <<<YAML
        services:
          edge-init:
            image: \${VORTOS_APP_IMAGE:?set VORTOS_APP_IMAGE to the deployed app image}
            command: >-
              php bin/console deploy:edge:hydrate-config
              --env=\${DEPLOY_ENV:-production}
              --out=/config/caddy.json
              --admin-listen=\${CADDY_ADMIN_LISTEN:-localhost:2019}
              --fallback=/bootstrap/caddy-config.json
            env_file:
              - /opt/vortos/.env.prod
            volumes:
              - \${EDGE_CONFIG_DIR:-/opt/vortos/edge/config}:/config
              - ./caddy-config.json:/bootstrap/caddy-config.json:ro
            networks:
              - vortos-net
            restart: "no"
          edge:
            # Overridable, and pinnable to a digest, via EDGE_IMAGE in the deploy env file.
            #
            # This container terminates TLS for the whole product and is the only one bound to
            # 0.0.0.0:80/443, so it is the single most exposed image in the deployment -- and it
            # was the only one whose tag could not be pinned. The default caddy:2-alpine is a
            # MOVING tag: a compose pull can change the bytes terminating TLS with no diff
            # anywhere, and conversely a stale local copy cannot be replaced without one. Neither
            # is acceptable for the edge.
            #
            # Default is unchanged, so nothing moves for a deployment that does not set it.
            image: \${EDGE_IMAGE:-caddy:2-alpine}
            container_name: vortos-edge
            restart: unless-stopped
            depends_on:
              edge-init:
                condition: service_completed_successfully
            ports:
              - "80:80"
              - "443:443"
              - "443:443/udp"
            volumes:
              - caddy_data:/data
              - \${EDGE_CONFIG_DIR:-/opt/vortos/edge/config}:/config
            command: caddy run --config /config/caddy.json
            ulimits:
              nofile:
                soft: {$nofile}
                hard: {$nofile}
            networks:
              - vortos-net

        volumes:
          caddy_data:

        networks:
          vortos-net:
            external: true
        YAML;
    }
}
