<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exposure\Ledger;

use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Vortos\FeatureFlags\Metrics\FlagEvaluationMetrics;
use Vortos\Http\Contract\TerminableMiddlewareInterface;
use Vortos\Http\Request;

/**
 * Writes the request's buffered exposures to the ledger, after the response has been sent.
 *
 * This is the only place in the exposure path that touches the network, and it runs where
 * that cannot be felt: `Vortos\Http\Kernel::terminate()` runs after the client already has
 * its response. The Redis dedupe round trip and the batched insert therefore cost the user
 * nothing, which is what allows the ledger to be complete rather than sampled.
 *
 * ## Why terminable middleware and not an event subscriber
 *
 * `Kernel::terminate()` dispatches no events — it iterates terminable middleware and nothing
 * else — so `KernelEvents::TERMINATE` never fires in this stack. That mistake previously cost
 * analytics its per-request flush and went unnoticed for weeks, because a batching buffer hid
 * it behind round hundreds. Implementing {@see TerminableMiddlewareInterface} is what the
 * kernel actually calls.
 *
 * ## Ordering against the analytics flush
 *
 * Both this and `Vortos\Analytics\Runtime\HttpTerminateFlush` are terminable middleware, and
 * neither depends on the other: they read different buffers and write to different places.
 * The ledger deliberately does not wait for, or care about, whether the analytics forward
 * succeeded — coupling them would make a third-party outage able to cost first-party data,
 * which is the exact failure mode this whole split exists to prevent.
 */
final readonly class ExposureLedgerFlusher implements TerminableMiddlewareInterface
{
    public function __construct(
        private LedgerExposureObserver $observer,
        private DailyExposureDeduper $deduper,
        private ExposureLedgerInterface $ledger,
        private FlagEvaluationMetrics $metrics,
    ) {}

    public function terminate(Request $request, Response $response): void
    {
        $this->flush();
    }

    /**
     * Drain, filter to the day grain, write.
     *
     * Public so that a non-HTTP entry point — a console command, a Kafka consumer — can flush
     * what it produced. Server-side evaluations happen on those paths too, and without this
     * their exposures would sit in the buffer until the worker reset discarded them, making
     * every background-evaluated flag look untouched.
     */
    public function flush(): void
    {
        try {
            $drained = $this->observer->drain();

            if ($drained === []) {
                return;
            }

            $fresh = array_values(array_filter(
                $drained,
                fn (LedgerExposure $e): bool => $this->deduper->isFirstToday($e),
            ));

            if ($fresh === []) {
                return;
            }

            $this->metrics->ledgerRows($this->ledger->append($fresh));
        } catch (Throwable) {
            // Intentionally swallowed: the response has already been sent, so there is nobody
            // left to tell, and losing an exposure must never become a second failure.
            //
            // Counted rather than merely ignored. A silently failing ledger is the one
            // failure mode this design cannot tolerate: it does not degrade the readout, it
            // biases it — and a biased readout is indistinguishable from a real result. The
            // counter is what lets an alert notice before anyone trusts a number.
            $this->metrics->ledgerWriteFailed();
        }
    }
}
