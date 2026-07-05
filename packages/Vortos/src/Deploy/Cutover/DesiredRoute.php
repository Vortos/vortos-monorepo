<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Compose\ColorEndpoint;
use Vortos\Deploy\Target\ActiveColor;

final readonly class DesiredRoute
{
    public function __construct(
        public string $env,
        public ActiveColor $activeColor,
        public ColorEndpoint $upstream,
        public int $drainDeadlineSeconds = 30,
        public int $weight = 100,
        /**
         * The public TLS domain this edge route serves. When set, the generated Caddy config carries a
         * host matcher + tls.automation for it, so a cutover PRESERVES the domain's certificate
         * instead of clobbering it to Caddy's internal default (GAP-D). Null only for an internal /
         * no-TLS edge.
         */
        public ?string $domain = null,
    ) {
        if ($drainDeadlineSeconds < 1) {
            throw new \InvalidArgumentException(sprintf('Drain deadline must be >= 1, got %d.', $drainDeadlineSeconds));
        }

        if ($weight < 0 || $weight > 100) {
            throw new \InvalidArgumentException(sprintf('Weight must be 0-100, got %d.', $weight));
        }

        if ($domain !== null && $domain === '') {
            throw new \InvalidArgumentException('Domain, when provided, must not be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'env' => $this->env,
            'active_color' => $this->activeColor->value,
            'upstream_host' => $this->upstream->host,
            'upstream_port' => $this->upstream->port,
            'drain_deadline_seconds' => $this->drainDeadlineSeconds,
            'weight' => $this->weight,
            'domain' => $this->domain,
        ];
    }
}
