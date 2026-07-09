<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

/** The result of an idempotent edge reconcile: whether the edge was recreated and the desired hash. */
final readonly class ReconcileEdgeOutcome
{
    private function __construct(
        public bool $converged,
        public string $hash,
    ) {}

    public static function converged(string $hash): self
    {
        return new self(true, $hash);
    }

    public static function unchanged(string $hash): self
    {
        return new self(false, $hash);
    }

    public function detail(): string
    {
        return $this->converged
            ? sprintf('edge reconciled (converged, hash=%s)', substr($this->hash, 0, 12))
            : sprintf('edge already converged (unchanged, hash=%s)', substr($this->hash, 0, 12));
    }
}
