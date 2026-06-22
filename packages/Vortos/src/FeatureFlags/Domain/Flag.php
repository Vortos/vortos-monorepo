<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain;

use Vortos\Domain\Aggregate\AggregateRoot;
use Vortos\Domain\Identity\AggregateId;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagExpirySetEvent;
use Vortos\FeatureFlags\Domain\Event\FlagLifecycleChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagOwnerSetEvent;
use Vortos\FeatureFlags\Domain\Event\FlagPromotedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRevertedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagScheduledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagVariantsChangedEvent;
use Vortos\FeatureFlags\Exception\FlagArchivedException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\RolloutSchedule;

/**
 * The Flag aggregate — the single write-side model for a feature flag.
 *
 * ## Why it exists
 *
 * Phase A mutated flags by calling `FlagStorageInterface::save()` directly from CLI
 * commands. That left no audit trail and no chokepoint to hang governance off. This
 * aggregate is that chokepoint: every mutation is a method here that records a
 * past-tense domain event ({@see Domain\Event}). The events are collected by the
 * {@see \Vortos\Domain\Event\DomainEventLedger} (via {@see AggregateRoot::recordEvent})
 * and dispatched by the owning bus inside the unit of work — the projection (Block 7
 * read model), webhooks (Block 18), and approvals (Block 14) all read this one stream.
 *
 * ## Relationship to FeatureFlag
 *
 * The aggregate does NOT replace the {@see FeatureFlag} value object — it *holds* one as
 * its current state and produces a new one on each mutation (preserving the existing
 * immutability + `with*` pattern). The write repository persists `state()`. The hot
 * read/eval path is untouched: it keeps reading `FeatureFlag` straight from storage and
 * never goes through this aggregate (PLATFORM §13.4).
 *
 * ## Environments (Block 10)
 *
 * Each flag aggregate instance is scoped to one environment (the one it was loaded for).
 * Mutations record `environment` on their events so the read model can key audit entries
 * and state views per `(env, flagName)`. The `FlagWriteService` is responsible for
 * loading the correct environment-composed state before passing to `reconstitute()`.
 *
 * Mutations are idempotent where it is meaningful — enabling an already-enabled flag, or
 * setting rules to their current value, records no event (no spurious audit noise).
 */
final class Flag extends AggregateRoot
{
    private function __construct(
        private readonly FlagId $id,
        private FeatureFlag $state,
        private bool $archived = false,
    ) {}

    public function getId(): AggregateId
    {
        return $this->id;
    }

    /** The current write-side state, ready to persist. */
    public function state(): FeatureFlag
    {
        return $this->state;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    /**
     * Reconstruct from persistence. Records no event — this is not a mutation.
     */
    public static function reconstitute(FeatureFlag $state): self
    {
        $flag = new self(FlagId::fromString($state->id), $state);
        $flag->restoreVersion(0);

        return $flag;
    }

    /**
     * Create a brand-new flag. Records {@see FlagCreatedEvent} carrying the full initial
     * snapshot (the baseline the History/revert read model rebuilds from).
     */
    public static function create(FeatureFlag $state, string $actorId, ?string $reason = null): self
    {
        $flag = new self(FlagId::fromString($state->id), $state);
        $flag->recordEvent(new FlagCreatedEvent(
            flagId:      $state->id,
            name:        $state->name,
            state:       $state->toArray(),
            actorId:     $actorId,
            reason:      $reason,
            environment: $state->environment,
        ));

        return $flag;
    }

    public function enable(string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        if ($this->state->enabled) {
            return;
        }

        $this->state = $this->state->withEnabled(true);
        $this->recordEvent(new FlagEnabledEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    public function disable(string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        if (!$this->state->enabled) {
            return;
        }

        $this->state = $this->state->withEnabled(false);
        $this->recordEvent(new FlagDisabledEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    /**
     * @param FlagRule[] $newRules
     */
    public function changeRules(array $newRules, string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        $old = array_map(static fn(FlagRule $r) => $r->toArray(), $this->state->rules);
        $new = array_map(static fn(FlagRule $r) => $r->toArray(), $newRules);

        if ($old === $new) {
            return;
        }

        $this->state = $this->state->withRules($newRules);
        $this->recordEvent(new FlagRulesChangedEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            oldRules:    $old,
            newRules:    $new,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    /**
     * @param array<string,int>|null $newVariants variant name → weight
     */
    public function changeVariants(?array $newVariants, string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        $old = $this->state->variants;
        if ($old === $newVariants) {
            return;
        }

        $this->state = $this->state->withVariants($newVariants);
        $this->recordEvent(new FlagVariantsChangedEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            oldVariants: $old,
            newVariants: $newVariants,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    public function schedule(?RolloutSchedule $schedule, string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        $old = $this->state->schedule?->toArray();
        $new = $schedule?->toArray();
        if ($old === $new) {
            return;
        }

        $this->state = $this->state->withSchedule($schedule);
        $this->recordEvent(new FlagScheduledEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            schedule:    $new,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    public function archive(string $actorId, ?string $reason = null): void
    {
        if ($this->archived) {
            return;
        }

        $this->recordEvent(new FlagArchivedEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            finalState:  $this->state->toArray(),
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
        $this->archived = true;
    }

    /**
     * Restore the flag to a prior state. The revert is itself an audited mutation —
     * never a silent state change. `$target` is a complete {@see FeatureFlag} (typically
     * rebuilt from an earlier audit-log snapshot) for the same flag.
     */
    public function revertTo(FeatureFlag $target, string $actorId, ?string $reason = null): void
    {
        $this->guardNotArchived();

        if ($target->id !== $this->state->id) {
            throw new \InvalidArgumentException('Revert target must be the same flag.');
        }

        $from = $this->state->toArray();
        $to   = $target->toArray();
        if ($from === $to) {
            return;
        }

        $this->state = $target;
        $this->recordEvent(new FlagRevertedEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            fromState:   $from,
            toState:     $to,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    public function changeLifecycle(FlagLifecycleState $next, string $actorId, ?string $reason = null): void
    {
        $current = $this->state->lifecycle;
        if ($current === $next) {
            return;
        }

        if (!$current->canTransitionTo($next)) {
            throw new \LogicException(sprintf(
                'Cannot transition flag "%s" from %s to %s.',
                $this->state->name,
                $current->value,
                $next->value,
            ));
        }

        if ($next === FlagLifecycleState::Archived) {
            $this->archived = true;
        }

        $this->state = $this->state->withLifecycle($next);
        $this->recordEvent(new FlagLifecycleChangedEvent(
            flagId:      $this->state->id,
            name:        $this->state->name,
            from:        $current,
            to:          $next,
            actorId:     $actorId,
            reason:      $reason,
            environment: $this->state->environment,
        ));
    }

    public function setOwner(?string $owner, string $actorId, ?string $reason = null): void
    {
        $previous = $this->state->owner;
        if ($previous === $owner) {
            return;
        }

        $this->state = $this->state->withOwner($owner);
        $this->recordEvent(new FlagOwnerSetEvent(
            flagId:        $this->state->id,
            name:          $this->state->name,
            previousOwner: $previous,
            newOwner:      $owner,
            actorId:       $actorId,
            reason:        $reason,
            environment:   $this->state->environment,
        ));
    }

    public function setExpiry(?\DateTimeImmutable $expiresAt, string $actorId, ?string $reason = null): void
    {
        $previous = $this->state->expiresAt;

        // Compare by formatted string to avoid object identity traps.
        $prevStr = $previous?->format(\DateTimeInterface::ATOM);
        $newStr  = $expiresAt?->format(\DateTimeInterface::ATOM);
        if ($prevStr === $newStr) {
            return;
        }

        $this->state = $this->state->withExpiry($expiresAt);
        $this->recordEvent(new FlagExpirySetEvent(
            flagId:          $this->state->id,
            name:            $this->state->name,
            previousExpiry:  $previous,
            newExpiry:       $expiresAt,
            actorId:         $actorId,
            reason:          $reason,
            environment:     $this->state->environment,
        ));
    }

    /**
     * Record that this flag's env state was promoted to another environment.
     * The actual state write is performed by {@see FlagPromotionService}; this
     * method only records the audit event on the source-flag aggregate.
     */
    public function recordPromotion(
        FlagEnvironmentState $promotedState,
        string $toEnvironment,
        string $actorId,
        ?string $reason = null,
    ): void {
        $this->recordEvent(new FlagPromotedEvent(
            flagId:           $this->state->id,
            name:             $this->state->name,
            fromEnvironment:  $this->state->environment,
            toEnvironment:    $toEnvironment,
            promotedState:    $promotedState->toArray(),
            actorId:          $actorId,
            reason:           $reason,
        ));
    }

    private function guardNotArchived(): void
    {
        if ($this->archived) {
            throw FlagArchivedException::forFlag($this->state->name);
        }
    }
}
