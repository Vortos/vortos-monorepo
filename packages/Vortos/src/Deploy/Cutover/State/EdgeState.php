<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\State;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Cutover\DesiredRoute;
use Vortos\Deploy\Target\ActiveColor;

/**
 * The persisted routing intent for one environment's edge — the single fact an edge node needs to
 * reconstruct its active-color route on boot: which color is live, on what upstream, at what canary
 * weight, and for which TLS domain.
 *
 * This is pure routing metadata — it carries NO secrets and NO rendered Caddy admin credentials, so
 * it is safe to store in a shared control-plane store (Redis, etc.). The concrete Caddy JSON is
 * re-rendered from this by {@see \Vortos\Deploy\Cutover\EdgeConfigGenerator}, so the config shape has
 * exactly one source of truth.
 */
final readonly class EdgeState
{
    public function __construct(
        public string $env,
        public ActiveColor $activeColor,
        public string $upstreamHost,
        public int $upstreamPort,
        public int $weight = 100,
        public ?string $domain = null,
        public int $version = 0,
        public ?string $updatedAt = null,
        /**
         * SHA-256 of the exact edge config that was loaded and written to the boot file at this
         * cutover — the drift anchor. A drift check re-hashes the on-box boot file and the live admin
         * config and compares them to this; a mismatch means a manual admin push, a stale boot file,
         * or an adapt-version skew. Carries no secret (a hash) so it stays safe in the shared store.
         */
        public ?string $configHash = null,
    ) {
        if ($env === '') {
            throw new \InvalidArgumentException('EdgeState.env must not be empty.');
        }
        if ($upstreamHost === '') {
            throw new \InvalidArgumentException('EdgeState.upstreamHost must not be empty.');
        }
        if ($upstreamPort < 1 || $upstreamPort > 65535) {
            throw new \InvalidArgumentException(sprintf('EdgeState.upstreamPort must be 1-65535, got %d.', $upstreamPort));
        }
        if ($weight < 0 || $weight > 100) {
            throw new \InvalidArgumentException(sprintf('EdgeState.weight must be 0-100, got %d.', $weight));
        }
        if ($domain !== null && $domain === '') {
            throw new \InvalidArgumentException('EdgeState.domain, when set, must not be empty.');
        }
    }

    public static function fromRoute(DesiredRoute $route): self
    {
        return new self(
            env: $route->env,
            activeColor: $route->activeColor,
            upstreamHost: $route->upstream->host,
            upstreamPort: $route->upstream->port,
            weight: $route->weight,
            domain: $route->domain,
        );
    }

    /** Reconstruct the desired route this state represents (for re-rendering the edge config). */
    public function toDesiredRoute(): DesiredRoute
    {
        return new DesiredRoute(
            env: $this->env,
            activeColor: $this->activeColor,
            upstream: new ColorEndpoint($this->upstreamHost, $this->upstreamPort),
            weight: $this->weight,
            domain: $this->domain,
        );
    }

    public function withVersion(int $version, string $updatedAt): self
    {
        return new self(
            env: $this->env,
            activeColor: $this->activeColor,
            upstreamHost: $this->upstreamHost,
            upstreamPort: $this->upstreamPort,
            weight: $this->weight,
            domain: $this->domain,
            version: $version,
            updatedAt: $updatedAt,
            configHash: $this->configHash,
        );
    }

    public function withConfigHash(?string $configHash): self
    {
        return new self(
            env: $this->env,
            activeColor: $this->activeColor,
            upstreamHost: $this->upstreamHost,
            upstreamPort: $this->upstreamPort,
            weight: $this->weight,
            domain: $this->domain,
            version: $this->version,
            updatedAt: $this->updatedAt,
            configHash: $configHash,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'active_color' => $this->activeColor->value,
            'upstream_host' => $this->upstreamHost,
            'upstream_port' => $this->upstreamPort,
            'weight' => $this->weight,
            'domain' => $this->domain,
            'version' => $this->version,
            'updated_at' => $this->updatedAt,
            'config_hash' => $this->configHash,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            env: (string) ($data['env'] ?? ''),
            activeColor: ActiveColor::from((string) ($data['active_color'] ?? 'none')),
            upstreamHost: (string) ($data['upstream_host'] ?? ''),
            upstreamPort: (int) ($data['upstream_port'] ?? 0),
            weight: (int) ($data['weight'] ?? 100),
            domain: isset($data['domain']) && $data['domain'] !== null ? (string) $data['domain'] : null,
            version: (int) ($data['version'] ?? 0),
            updatedAt: isset($data['updated_at']) && $data['updated_at'] !== null ? (string) $data['updated_at'] : null,
            configHash: isset($data['config_hash']) && $data['config_hash'] !== null ? (string) $data['config_hash'] : null,
        );
    }
}
