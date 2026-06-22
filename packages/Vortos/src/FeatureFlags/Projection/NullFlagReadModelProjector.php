<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Projection;

use Vortos\Domain\Event\EventEnvelope;

/**
 * No-op projector — used when the Mongo read store is unavailable (e.g. a runtime-only
 * app that never touches the admin/write plane). The event stream is still emitted; the
 * read models simply are not maintained in that deployment.
 */
final class NullFlagReadModelProjector implements FlagReadModelProjectorInterface
{
    public function apply(EventEnvelope $envelope): void
    {
        // intentionally empty
    }
}
