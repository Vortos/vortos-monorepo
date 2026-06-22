<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Projection;

use Vortos\Domain\Event\EventEnvelope;

/**
 * Projects a flag domain event into the read models (audit log + state view).
 *
 * Implementations MUST be idempotent (upsert, never insert) — the same event may be
 * applied more than once (re-delivery, replay).
 */
interface FlagReadModelProjectorInterface
{
    public function apply(EventEnvelope $envelope): void;
}
