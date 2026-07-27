<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration\Messaging;

use DateTimeImmutable;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Integration\AlertSourceInterface;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Sample\ThresholdSample;

/**
 * Evaluates `queue_lag` rules against registered {@see QueueBacklogProviderInterface}s — dead-letter
 * depth, outbox depth, and the age of the oldest stuck message.
 *
 * Depth and age are deliberately both available. Depth alone misses the worst case: a queue holding
 * a steady three messages looks harmless, but if they are the *same* three from six hours ago,
 * nothing is draining. A rule selects which it means with the `measure` label ("depth", the default,
 * or "oldest_age_seconds").
 *
 * SCOPING A RULE. `queue` matches one queue exactly; `queue_prefix` matches a whole surface. With
 * neither, a rule evaluates against EVERY queue every provider reports — which is almost never what
 * the author meant, because queue names carry the transport and the messaging provider names
 * transports after topics. A rule intended as "anything in the dead-letter queue" would, unscoped,
 * also fire on the first ordinary pending outbox row, and an "oldest message is too old" rule would
 * latch permanently against the abandoned-messages surface, whose age only ever grows.
 *
 * Prefer `queue_prefix`: the exact names are per-topic and unbounded (`dlq:registration.submitted`,
 * `outbox-failed:registration.payment.completed`), so an exact `queue` label only works when the
 * caller already knows every topic — and silently matches nothing when it does not. The three
 * surfaces are `dlq:`, `outbox:` and `outbox-failed:`; note `outbox:` does not match
 * `outbox-failed:`, so the two remain cleanly separable.
 */
final class QueueBacklogAlertSource implements AlertSourceInterface
{
    private const MEASURE_DEPTH = 'depth';
    private const MEASURE_OLDEST_AGE = 'oldest_age_seconds';

    /**
     * @param iterable<QueueBacklogProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly AlertRuleSet $rules,
        private readonly AlertRuleEvaluator $evaluator,
        private readonly AlertDispatcherInterface $dispatcher,
    ) {}

    /** @return list<DispatchResult> */
    public function tick(string $env, DateTimeImmutable $now): array
    {
        $results = [];

        foreach ($this->rules->all() as $rule) {
            if ($rule->kind !== AlertRuleKind::QueueLag) {
                continue;
            }

            $wantedQueue = $rule->labels['queue'] ?? null;
            $wantedPrefix = $rule->labels['queue_prefix'] ?? null;
            $measure = $rule->labels['measure'] ?? self::MEASURE_DEPTH;

            foreach ($this->providers as $provider) {
                foreach ($provider->backlogs() as $backlog) {
                    if ($wantedQueue !== null && $backlog->queue !== $wantedQueue) {
                        continue;
                    }

                    if ($wantedPrefix !== null && !str_starts_with($backlog->queue, $wantedPrefix)) {
                        continue;
                    }

                    $value = $this->measure($backlog, $measure);

                    // No reading for this measure — an outbox with nothing pending has no "oldest"
                    // age. Evaluating 0 would silently resolve an alert that was never assessed.
                    if ($value === null) {
                        continue;
                    }

                    $event = $this->evaluator->evaluate(
                        $rule,
                        new ThresholdSample($value),
                        $env,
                        null,
                        $now,
                        // Distinguishes per-queue alerts in the dedupe fingerprint, so a backed-up
                        // DLQ transport does not suppress the alert for a different one.
                        ['queue' => $backlog->queue, 'source' => $provider->name()],
                    );

                    if ($event !== null) {
                        $results[] = $this->dispatcher->dispatch($event, $rule->routingOverride);
                    }
                }
            }
        }

        return $results;
    }

    private function measure(QueueBacklog $backlog, string $measure): ?float
    {
        return match ($measure) {
            self::MEASURE_OLDEST_AGE => $backlog->oldestAgeSeconds === null ? null : (float) $backlog->oldestAgeSeconds,
            default => (float) $backlog->depth,
        };
    }
}
