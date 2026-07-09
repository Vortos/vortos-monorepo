<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Drift;

/**
 * The result of an edge drift check: whether the live edge still matches the recorded routing intent,
 * and — when it does not — the secret-free reasons why (a manual admin push, a stale boot file, an
 * unreachable admin, an adapt-version skew).
 */
final readonly class EdgeDriftReport
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public bool $hasState,
        public bool $inSync,
        public array $reasons,
        public ?string $expectedDial = null,
    ) {}

    public static function noState(): self
    {
        return new self(hasState: false, inSync: true, reasons: []);
    }

    public static function inSync(string $expectedDial): self
    {
        return new self(hasState: true, inSync: true, reasons: [], expectedDial: $expectedDial);
    }

    /** @param list<string> $reasons */
    public static function drifted(array $reasons, ?string $expectedDial): self
    {
        return new self(hasState: true, inSync: false, reasons: $reasons, expectedDial: $expectedDial);
    }

    public function summary(): string
    {
        if (!$this->hasState) {
            return 'no recorded edge state yet (edge has not cut over)';
        }

        return $this->inSync
            ? sprintf('edge in sync with recorded intent (%s)', $this->expectedDial ?? 'n/a')
            : 'edge drift detected: ' . implode('; ', $this->reasons);
    }
}
