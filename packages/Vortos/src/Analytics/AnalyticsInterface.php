<?php

declare(strict_types=1);

namespace Vortos\Analytics;

use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\OpsKit\Driver\DriverInterface;

/**
 * The product-analytics port (`capture`/`identify`/`group`) — "what are *users*
 * doing?" — distinct from the system-observability and error-tracking telemetry
 * layers, which must never be conflated with this one (architecture-test enforced).
 *
 * Contract (TCK-asserted, identical discipline to the observability error-sink
 * contract): every method is **best-effort and MUST NOT throw into the caller** —
 * a product-analytics backend being down can never become a second failure on the
 * request path.
 *
 * App code always receives the composed decorator chain (privacy filtering then
 * batching) wrapping the selected driver — never the bare driver — so privacy and
 * batching can never be bypassed (see `AnalyticsExtension`).
 */
interface AnalyticsInterface extends DriverInterface
{
    /** Stable lower-kebab key; equals the driver's #[AsDriver] key. */
    public function name(): string;

    /** Record a product event. Best-effort; MUST NOT throw into the caller. */
    public function capture(AnalyticsEvent $event): void;

    /** Set/merge identity traits. Idempotent + batched. Best-effort; never throws. */
    public function identify(IdentitySet $identity): void;

    /** Associate a distinctId with a group (org/team/account). Idempotent; never throws. */
    public function group(GroupAssociation $group): void;

    /** Drain any buffered events toward the backend. Best-effort; never throws. */
    public function flush(): void;
}
