<?php

declare(strict_types=1);

namespace Vortos\Analytics\Runtime;

use Throwable;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Event\AnalyticsEvent;
use Vortos\Analytics\Event\GroupAssociation;
use Vortos\Analytics\Event\IdentitySet;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The outermost decorator app code receives as `AnalyticsInterface` — wraps
 * {@see PrivacyFilteringAnalytics} with bounded batching and idempotent
 * identify/group.
 *
 * `capture()` enqueues into a **bounded** in-memory ring buffer
 * (`$bufferMax`, default 500); on overflow the **oldest** event is dropped and
 * `$droppedTotal` increments — the buffer never grows unbounded and `capture()`
 * never blocks. A flush is triggered once the buffer reaches `$flushAt`
 * (default 100), on an explicit {@see flush()} call, or — wired by the DI
 * extension — on `kernel.terminate`.
 *
 * `identify()`/`group()` are made idempotent within a window via
 * {@see IdentityDedupeCache}: an identical repeated call collapses to a no-op,
 * respecting provider rate limits without app-code complexity.
 *
 * **Durability (optional):** when an {@see AnalyticsSpool} is configured,
 * `flush()` writes the buffered batch to the spool instead of calling the inner
 * driver synchronously — the request path never performs network I/O. A separate
 * `analytics:flush` command later drains the spool out-of-band.
 */
final class BatchingAnalytics implements AnalyticsInterface
{
    /** @var list<AnalyticsEvent> */
    private array $eventBuffer = [];

    private int $droppedTotal = 0;

    public function __construct(
        private readonly AnalyticsInterface $inner,
        private readonly IdentityDedupeCache $dedupeCache,
        private readonly int $bufferMax = 500,
        private readonly int $flushAt = 100,
        private readonly ?AnalyticsSpool $spool = null,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function capture(AnalyticsEvent $event): void
    {
        try {
            $this->eventBuffer[] = $event;

            if (count($this->eventBuffer) > $this->bufferMax) {
                array_shift($this->eventBuffer);
                $this->droppedTotal++;
            }

            if (count($this->eventBuffer) >= $this->flushAt) {
                $this->flush();
            }
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function identify(IdentitySet $identity): void
    {
        try {
            if ($this->dedupeCache->seenIdentity($identity)) {
                return;
            }
            $this->inner->identify($identity);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function group(GroupAssociation $group): void
    {
        try {
            if ($this->dedupeCache->seenGroup($group)) {
                return;
            }
            $this->inner->group($group);
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function flush(): void
    {
        $batch = $this->eventBuffer;
        $this->eventBuffer = [];

        try {
            if ($this->spool !== null && $batch !== []) {
                foreach ($batch as $event) {
                    $this->spool->enqueue($event);
                }

                return; // Durable path: request stops here, analytics:flush drains out-of-band.
            }

            foreach ($batch as $event) {
                $this->inner->capture($event);
            }

            $this->inner->flush();
        } catch (Throwable) {
            // Intentionally swallowed.
        }
    }

    public function droppedTotal(): int
    {
        return $this->droppedTotal;
    }

    /** @internal test/inspection seam */
    public function bufferedCount(): int
    {
        return count($this->eventBuffer);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return $this->inner->capabilities();
    }
}
