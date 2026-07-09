<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * The edge config produced for a desired route, plus the provenance needed for audit and drift.
 *
 * {@see $usedBaseConfig} distinguishes the two precedence paths: true when the operator's hand-written
 * base config drove the result (adapt-merge), false when it was generated from scratch (backward-
 * compatible path). The hashes are recorded in the release manifest and audit ledger — never the body.
 */
final readonly class AssembledEdgeConfig
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public array $config,
        public bool $usedBaseConfig,
        public ?string $baseConfigSha256 = null,
        public ?MergeOutcome $mergeOutcome = null,
    ) {}

    public function toJson(): string
    {
        return json_encode(
            $this->config,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        );
    }

    /**
     * The canonical hash of the FINAL config that is actually loaded and written to the boot file
     * (after the firewall's admin pin) — the anchor the drift check compares the on-box file against.
     */
    public function finalSha256(): string
    {
        return hash('sha256', MergeOutcome::canonicalize($this->config));
    }

    public function mergedSha256(): ?string
    {
        return $this->mergeOutcome?->sha256;
    }
}
