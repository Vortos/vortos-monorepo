<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use Vortos\Alerts\Event\AlertEvent;

/**
 * Pure decision over `(AlertEvent, state, policy, clock)` (§3.5). All
 * time-dependence is injected (the caller supplies `$now`), so escalation timers are
 * deterministically testable.
 */
final class EscalationEngine
{
    public function __construct(
        private readonly EscalationPolicy $policy,
        private readonly OnCallRotation $rotation,
        private readonly QuietHoursPolicy $quietHours,
    ) {}

    /**
     * The first decision for a newly-firing alert.
     *
     * @return array{0: EscalationDecision, 1: EscalationState}
     */
    public function start(AlertEvent $event, DateTimeImmutable $now): array
    {
        $state = EscalationState::start($event->ruleId, $now);

        $suppressed = $this->suppression($event, $now, []);
        if ($suppressed !== null) {
            return [$suppressed, $state];
        }

        return [EscalationDecision::notify(0, 'initial page'), $state];
    }

    /**
     * @param list<MaintenanceSilence> $activeSilences silences currently covering $event->ruleId
     * @return array{0: EscalationDecision, 1: EscalationState}
     */
    public function tick(
        AlertEvent $event,
        EscalationState $state,
        ?Acknowledgement $ack,
        array $activeSilences,
        DateTimeImmutable $now,
    ): array {
        if ($state->stopped) {
            return [EscalationDecision::stop('already stopped'), $state];
        }

        if ($ack !== null) {
            return [EscalationDecision::stop('acknowledged'), $state->withStopped()];
        }

        $suppressed = $this->suppression($event, $now, $activeSilences);
        if ($suppressed !== null) {
            return [$suppressed, $state];
        }

        $tier = $this->policy->get($state->currentTier);
        $elapsed = $now->getTimestamp() - $state->tierStartedAt->getTimestamp();

        if ($elapsed < $tier->waitSeconds) {
            return [EscalationDecision::wait('tier wait not yet elapsed'), $state];
        }

        $nextTier = $state->currentTier + 1;
        if (!$this->policy->has($nextTier)) {
            return [EscalationDecision::stop('escalation exhausted'), $state->withStopped()];
        }

        return [EscalationDecision::notify($nextTier, 'unacked critical re-paging next tier'), $state->withTier($nextTier, $now)];
    }

    /** @param list<MaintenanceSilence> $activeSilences */
    private function suppression(AlertEvent $event, DateTimeImmutable $now, array $activeSilences): ?EscalationDecision
    {
        foreach ($activeSilences as $silence) {
            if ($silence->coversRule($event->ruleId) && $silence->isActiveAt($now)) {
                return EscalationDecision::suppress('maintenance silence');
            }
        }

        if (!$event->severity->isPaging()) {
            $responder = $this->rotation->currentResponder($now);
            if ($this->quietHours->isQuietFor($responder, $now)) {
                return EscalationDecision::suppress('quiet hours');
            }
        }

        return null;
    }
}
