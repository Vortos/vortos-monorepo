<?php

declare(strict_types=1);

namespace Vortos\Alerts\Runtime;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;
use Vortos\Alerts\Dedupe\AlertStateStatus;
use Vortos\Alerts\Dedupe\AlertStateStoreInterface;

/**
 * Closes alerts whose condition has stopped being observed.
 *
 * `AlertStateStatus::Resolved` existed as a value that no code ever assigned, so every alert ever
 * raised stayed `open` for the lifetime of the system. That is not a noise problem — a condition
 * that stops firing stops being dispatched, so nobody is paged — it is a TRUST problem: "how many
 * things are wrong right now?" had no answer, because the count only ever grew. An operator who
 * cannot believe the number stops looking at it, which costs exactly as much as having no alerting.
 *
 * Resolution is inferred from absence rather than announced, because alert sources report
 * conditions, not recoveries: a disk that drops below its threshold simply stops appearing in the
 * evaluation. So an alert not seen for several evaluation cycles is treated as recovered.
 *
 * The grace multiplier matters. Closing an alert the moment it misses one tick would flap against
 * any condition that oscillates around its threshold, and re-announce it on the next tick — the
 * exact pager storm the dedupe layer exists to prevent. Several cycles of silence is strong
 * evidence the condition is genuinely gone.
 */
final class StaleAlertResolver
{
    public function __construct(
        private readonly AlertStateStoreInterface $store,
        /**
         * How long an open alert must go unobserved before it is considered recovered. Defaults to
         * an hour: comfortably longer than any source's evaluation cadence, short enough that a
         * fixed problem clears from the board within the same working session.
         */
        private readonly int $silenceSeconds = 3_600,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * @return int how many alerts were closed
     */
    public function resolveStale(DateTimeImmutable $now): int
    {
        $threshold = $now->modify(sprintf('-%d seconds', $this->silenceSeconds));
        $closed = 0;

        try {
            $stale = $this->store->openSince($threshold);
        } catch (Throwable $e) {
            // Never let housekeeping break the alert tick that runs alongside it.
            $this->logger?->error('Could not read open alerts to resolve stale ones.', ['exception' => $e]);

            return 0;
        }

        foreach ($stale as $state) {
            try {
                $this->store->save($state->withStatus(AlertStateStatus::Resolved, $now));
                $closed++;
            } catch (Throwable $e) {
                $this->logger?->error('Could not resolve a stale alert.', [
                    'fingerprint' => $state->fingerprint,
                    'exception' => $e,
                ]);
            }
        }

        return $closed;
    }
}
