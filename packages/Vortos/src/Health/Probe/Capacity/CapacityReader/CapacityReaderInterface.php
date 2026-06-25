<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity\CapacityReader;

/**
 * The only I/O seam for capacity probes — everything above this interface is
 * unit-testable without a real `/proc`/`df`. Every method returns `null` on any
 * read/parse failure (missing field, zero cores, unreadable file) rather than
 * throwing: a capacity probe degrading to "unknown" must never crash the readiness
 * fan-out.
 */
interface CapacityReaderInterface
{
    /** @return float|null used percentage (0-100), or null if undeterminable */
    public function diskUsedPct(string $path): ?float;

    /** @return float|null used percentage (0-100), or null if undeterminable */
    public function memoryUsedPct(): ?float;

    /** @return float|null load average normalized to 0-100+ (100 = fully loaded across all cores) */
    public function cpuLoadPct(): ?float;
}
