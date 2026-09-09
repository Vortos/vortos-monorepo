<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure;

/**
 * Read-only access to the flags evaluated during the current request.
 *
 * The write side ({@see ActiveFlagCollector}) belongs to the flag registry; consumers such as
 * an analytics enricher only ever read. Depending on this role rather than the concrete
 * collector keeps the collector `final` while still leaving a seam for a consumer to prove it
 * survives a failing source — which matters, because that consumer's contract is that losing
 * attribution must never cost the caller their event.
 */
interface ActiveFlagSourceInterface
{
    /** @return array<string,string> flag name => evaluated variant */
    public function all(): array;
}
