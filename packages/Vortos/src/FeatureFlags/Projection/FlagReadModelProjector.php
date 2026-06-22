<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Projection;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRevertedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagScheduledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagVariantsChangedEvent;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;

/**
 * Turns a flag domain event into read-model writes (Block 7, extended Block 10).
 *
 * Two projections per event:
 *   1. an append-only {@see FlagAuditEntry} (keyed by event id — idempotent),
 *   2. an update to the current {@see FlagStateView} (keyed by (environment, flag_name) — idempotent).
 *
 * Replaying the event stream in order reproduces the same state view, and re-applying any
 * single event is a no-op beyond the upsert (Kafka at-least-once / replay safe). All
 * upserts, never inserts (Golden Rule #5).
 *
 * Block 10: events carry an `environment` field (default 'production' for replay of
 * legacy events). The state view is keyed by the compound `(env, name)` — one row per
 * environment the flag has been mutated in.
 *
 * Structured so each `__invoke`-style branch consumes one event type: swapping this from
 * the synchronous write-time projection to an async `#[AsProjectionHandler]` Kafka
 * consumer later is mechanical.
 */
final class FlagReadModelProjector implements FlagReadModelProjectorInterface
{
    public function __construct(
        private readonly FlagAuditLogRepositoryInterface $auditLog,
        private readonly FlagStateViewRepositoryInterface $stateView,
    ) {}

    public function apply(EventEnvelope $envelope): void
    {
        $event      = $envelope->payload;
        $eventType  = (new \ReflectionClass($event))->getShortName();
        $occurredAt = $envelope->occurredAt->format(\DateTimeInterface::ATOM);

        $this->auditLog->upsert($this->auditEntry($envelope, $eventType, $occurredAt));
        $this->projectState($event, $eventType, $occurredAt);
    }

    private function auditEntry(EventEnvelope $envelope, string $eventType, string $occurredAt): FlagAuditEntry
    {
        $event = $envelope->payload;

        return new FlagAuditEntry(
            eventId:     $envelope->eventId,
            flagId:      $event->flagId,
            flagName:    $event->name,
            eventType:   $eventType,
            actorId:     $event->actorId,
            reason:      $event->reason,
            occurredAt:  $occurredAt,
            data:        $this->auditData($event),
            environment: $event->environment ?? 'production',
        );
    }

    /** @return array<string,mixed> */
    private function auditData(object $event): array
    {
        return match (true) {
            $event instanceof FlagCreatedEvent        => ['state' => $event->state],
            $event instanceof FlagRulesChangedEvent   => ['old_rules' => $event->oldRules, 'new_rules' => $event->newRules],
            $event instanceof FlagVariantsChangedEvent => ['old_variants' => $event->oldVariants, 'new_variants' => $event->newVariants],
            $event instanceof FlagScheduledEvent      => ['schedule' => $event->schedule],
            $event instanceof FlagArchivedEvent       => ['final_state' => $event->finalState],
            $event instanceof FlagRevertedEvent       => ['from_state' => $event->fromState, 'to_state' => $event->toState],
            default                                    => [],
        };
    }

    private function projectState(object $event, string $eventType, string $occurredAt): void
    {
        $env = $event->environment ?? 'production';

        // Events that carry a full snapshot rebuild the whole view.
        if ($event instanceof FlagCreatedEvent) {
            $this->stateView->upsert($this->fromSnapshot($event->state, $event, $eventType, $occurredAt, archived: false, environment: $env));
            return;
        }
        if ($event instanceof FlagRevertedEvent) {
            $this->stateView->upsert($this->fromSnapshot($event->toState, $event, $eventType, $occurredAt, archived: false, environment: $env));
            return;
        }

        $current = $this->stateView->findByName($event->name, $env) ?? $this->seed($event, $env);

        $enabled   = $current->enabled;
        $archived  = $current->archived;
        $ruleCount = $current->ruleCount;
        $variants  = $current->variants;
        $scheduled = $current->scheduled;

        switch (true) {
            case $event instanceof FlagEnabledEvent:
                $enabled = true;
                break;
            case $event instanceof FlagDisabledEvent:
                $enabled = false;
                break;
            case $event instanceof FlagRulesChangedEvent:
                $ruleCount = count($event->newRules);
                break;
            case $event instanceof FlagVariantsChangedEvent:
                $variants = $event->newVariants;
                break;
            case $event instanceof FlagScheduledEvent:
                $scheduled = $event->schedule !== null;
                break;
            case $event instanceof FlagArchivedEvent:
                $archived = true;
                break;
        }

        $this->stateView->upsert(new FlagStateView(
            flagName:      $event->name,
            flagId:        $event->flagId,
            enabled:       $enabled,
            archived:      $archived,
            valueType:     $current->valueType,
            kind:          $current->kind,
            ruleCount:     $ruleCount,
            variants:      $variants,
            scheduled:     $scheduled,
            lastEventType: $eventType,
            lastActorId:   $event->actorId,
            updatedAt:     $occurredAt,
            environment:   $env,
        ));
    }

    /**
     * @param array<string,mixed> $snapshot FeatureFlag::toArray() shape
     */
    private function fromSnapshot(array $snapshot, object $event, string $eventType, string $occurredAt, bool $archived, string $environment): FlagStateView
    {
        return new FlagStateView(
            flagName:      $event->name,
            flagId:        $event->flagId,
            enabled:       (bool) ($snapshot['enabled'] ?? false),
            archived:      $archived,
            valueType:     (string) ($snapshot['value_type'] ?? 'bool'),
            kind:          (string) ($snapshot['kind'] ?? 'release'),
            ruleCount:     count((array) ($snapshot['rules'] ?? [])),
            variants:      $snapshot['variants'] ?? null,
            scheduled:     ($snapshot['schedule'] ?? null) !== null,
            lastEventType: $eventType,
            lastActorId:   $event->actorId,
            updatedAt:     $occurredAt,
            environment:   $environment,
        );
    }

    /** A minimal seed when a partial event arrives with no projected view yet. */
    private function seed(object $event, string $environment): FlagStateView
    {
        return new FlagStateView(
            flagName:      $event->name,
            flagId:        $event->flagId,
            enabled:       false,
            archived:      false,
            valueType:     'bool',
            kind:          'release',
            ruleCount:     0,
            variants:      null,
            scheduled:     false,
            lastEventType: '',
            lastActorId:   '',
            updatedAt:     '',
            environment:   $environment,
        );
    }
}
