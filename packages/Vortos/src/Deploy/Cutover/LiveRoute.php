<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\Deploy\Target\ActiveColor;

final readonly class LiveRoute
{
    public function __construct(
        public ActiveColor $activeColor,
        public string $upstreamHost,
        public int $upstreamPort,
        public int $weight = 100,
    ) {}

    public function equalsDesired(DesiredRoute $desired): bool
    {
        return $this->activeColor === $desired->activeColor
            && $this->upstreamHost === $desired->upstream->host
            && $this->upstreamPort === $desired->upstream->port
            && $this->weight === $desired->weight;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active_color' => $this->activeColor->value,
            'upstream_host' => $this->upstreamHost,
            'upstream_port' => $this->upstreamPort,
            'weight' => $this->weight,
        ];
    }
}
