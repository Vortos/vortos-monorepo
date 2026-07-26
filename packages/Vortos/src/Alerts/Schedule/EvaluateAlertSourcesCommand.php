<?php

declare(strict_types=1);

namespace Vortos\Alerts\Schedule;

use Vortos\Domain\Command\CommandInterface;
use Vortos\Scheduler\Security\Attribute\SchedulableCommand;

/**
 * Fired on a cadence by {@see EvaluateAlertSourcesSchedule} to run every registered alert source.
 *
 * Alerting had every piece except this one: rules, evaluator, dedupe, flap damping, escalation and
 * the Slack notifier were all wired, and the sources were in the container — but nothing called
 * tick(), so no rule could ever fire. Registering a rule reads like it arms it; it does not.
 *
 * Carries no payload: an alert evaluation is only meaningful against current state.
 */
#[SchedulableCommand]
final readonly class EvaluateAlertSourcesCommand implements CommandInterface
{
    public function idempotencyKey(): ?string
    {
        return null;
    }
}
