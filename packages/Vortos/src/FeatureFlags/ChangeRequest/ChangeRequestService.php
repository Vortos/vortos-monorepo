<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Vortos\FeatureFlags\Application\FlagPromotionService;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestAppliedEvent;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestCancelledEvent;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestCreatedEvent;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestStatusChangedEvent;
use Vortos\FeatureFlags\ChangeRequest\Domain\Event\ChangeRequestVotedEvent;
use Vortos\FeatureFlags\ChangeRequest\Storage\ChangeRequestStorageInterface;
use Vortos\FeatureFlags\ChangeRequest\ChangeRequestConflictException;
use Vortos\FeatureFlags\ChangeRequest\Support\EventEnvelopeFactory;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\SystemClock;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * Block 14 — the orchestration boundary for the change-request (4-eyes) workflow.
 *
 * Every mutation a change request represents is ultimately applied through
 * {@see FlagWriteService} / {@see FlagPromotionService} — never the flag storage
 * directly — so the WriteBoundary invariant (and the audit ledger) stay intact even for
 * gated, deferred changes. The request lifecycle itself (create → vote → approve →
 * apply / reject / cancel / expire) is enforced by the {@see ChangeRequest} aggregate;
 * this service brackets each transition in a unit of work and publishes the
 * corresponding domain event after the state is durably persisted.
 */
final class ChangeRequestService
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly ChangeRequestStorageInterface $storage,
        private readonly ChangeRequestPolicy $policy,
        private readonly FlagWriteService $writeService,
        private readonly FlagPromotionService $promotionService,
        private readonly UnitOfWorkInterface $unitOfWork,
        private readonly EventBusInterface $eventBus,
        private readonly FlagScopeContext $scopeContext,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function create(
        string $flagName,
        string $projectId,
        string $environment,
        ChangeType $changeType,
        array $payload,
        string $reason,
        string $requestedBy,
        ?\DateTimeImmutable $applyAt = null,
    ): ChangeRequest {
        $now      = $this->clock->now();
        $required = $this->policy->requiredApprovals($environment);
        $ttl      = $this->policy->requestTtl($environment);

        $request = ChangeRequest::create(
            id:                Uuid::v7()->toRfc4122(),
            flagName:          $flagName,
            projectId:         $projectId,
            environment:       $environment,
            changeType:        $changeType,
            payload:           $payload,
            reason:            $reason,
            requestedBy:       $requestedBy,
            requestedAt:       $now,
            requiredApprovals: $required,
            expiresAt:         $now->modify('+' . $ttl . ' seconds'),
            applyAt:           $applyAt,
        );

        return $this->unitOfWork->run(function () use ($request, $now): ChangeRequest {
            $this->storage->save($request);
            $this->emit($request->id(), new ChangeRequestCreatedEvent(
                $request->id(),
                $request->flagName(),
                $request->projectId(),
                $request->environment(),
                $request->changeType(),
                $request->payload(),
                $request->reason(),
                $request->requestedBy(),
                $request->requestedAt(),
                $request->requiredApprovals(),
                $request->expiresAt(),
                $request->applyAt(),
            ));

            return $request;
        });
    }

    public function vote(string $id, string $actorId, bool $approve, string $reason): ChangeRequest
    {
        return $this->unitOfWork->run(function () use ($id, $actorId, $approve, $reason): ChangeRequest {
            $request  = $this->mustFind($id);
            $previous = $request->status();
            $now      = $this->clock->now();

            if ($approve) {
                $request->addApproval($actorId, $reason, $now);
            } else {
                $request->addRejection($actorId, $reason, $now);
            }

            $this->storage->save($request);

            $this->emit($request->id(), new ChangeRequestVotedEvent(
                $request->id(),
                $request->flagName(),
                $actorId,
                $approve,
                $reason,
                $now,
            ));

            if ($request->status() !== $previous) {
                $this->emit($request->id(), new ChangeRequestStatusChangedEvent(
                    $request->id(),
                    $request->flagName(),
                    $previous,
                    $request->status(),
                    $now,
                ));
            }

            return $request;
        });
    }

    public function cancel(string $id, string $actorId): ChangeRequest
    {
        return $this->unitOfWork->run(function () use ($id, $actorId): ChangeRequest {
            $request  = $this->mustFind($id);
            $previous = $request->status();
            $now      = $this->clock->now();

            $request->cancel($actorId);
            $this->storage->save($request);

            $this->emit($request->id(), new ChangeRequestCancelledEvent(
                $request->id(),
                $request->flagName(),
                $actorId,
                $now,
            ));
            $this->emit($request->id(), new ChangeRequestStatusChangedEvent(
                $request->id(),
                $request->flagName(),
                $previous,
                $request->status(),
                $now,
            ));

            return $request;
        });
    }

    public function apply(string $id, string $appliedBy): ChangeRequest
    {
        return $this->unitOfWork->run(function () use ($id, $appliedBy): ChangeRequest {
            $request = $this->mustFind($id);

            if ($request->status() !== ChangeRequestStatus::Approved) {
                throw new \DomainException(sprintf(
                    'Change request "%s" cannot be applied — status is "%s", expected "approved".',
                    $id,
                    $request->status()->value,
                ));
            }

            // Conflict detection: abort if any other CR for the same flag + environment was
            // applied after this request was created (competing concurrent change).
            $competing = array_filter(
                $this->storage->findByFlag(
                    $request->flagName(),
                    $request->projectId(),
                    $request->environment(),
                    ChangeRequestStatus::Applied,
                ),
                fn(ChangeRequest $cr) => $cr->id() !== $id && $cr->appliedAt() > $request->requestedAt(),
            );
            if (!empty($competing)) {
                throw new ChangeRequestConflictException(
                    array_values(array_map(fn(ChangeRequest $cr) => $cr->id(), $competing)),
                    $request->flagName(),
                );
            }

            $now = $this->clock->now();

            $this->applyPayload(
                $request->changeType(),
                $request->payload(),
                $request->flagName(),
                $request->environment(),
                $appliedBy,
            );

            $request->markApplied($appliedBy, $now);
            $this->storage->save($request);

            $this->emit($request->id(), new ChangeRequestAppliedEvent(
                $request->id(),
                $request->flagName(),
                $request->projectId(),
                $request->environment(),
                $request->changeType(),
                $appliedBy,
                $now,
            ));
            $this->emit($request->id(), new ChangeRequestStatusChangedEvent(
                $request->id(),
                $request->flagName(),
                ChangeRequestStatus::Approved,
                ChangeRequestStatus::Applied,
                $now,
            ));

            return $request;
        });
    }

    /**
     * Map a change type + payload onto the audited write boundary, in the request's
     * environment scope. Never touches flag storage directly.
     */
    public function applyPayload(
        ChangeType $changeType,
        array $payload,
        string $flagName,
        string $environment,
        string $actorId,
    ): void {
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        $this->scopeContext->runAs($environment, function () use ($changeType, $payload, $flagName, $actorId, $reason): void {
            match ($changeType) {
                ChangeType::Enable => $this->writeService->enable($flagName, $actorId, $reason),
                ChangeType::Disable => $this->writeService->disable($flagName, $actorId, $reason),
                ChangeType::UpdateRules => $this->writeService->changeRules(
                    $flagName,
                    array_map(fn(array $r) => FlagRule::fromArray($r), $payload['rules'] ?? []),
                    $actorId,
                    $reason,
                ),
                ChangeType::UpdateVariants => $this->writeService->changeVariants(
                    $flagName,
                    $payload['variants'] ?? null,
                    $actorId,
                    $reason,
                ),
                ChangeType::UpdateSchedule => $this->writeService->schedule(
                    $flagName,
                    isset($payload['schedule']) ? RolloutSchedule::fromArray($payload['schedule']) : null,
                    $actorId,
                    $reason,
                ),
                ChangeType::Archive => $this->writeService->changeLifecycle(
                    $flagName,
                    FlagLifecycleState::Archived,
                    $actorId,
                    $reason,
                ),
                ChangeType::UpdateMetadata => $this->applyMetadata($flagName, $payload, $actorId, $reason),
                ChangeType::Promote => $this->promotionService->promote(
                    $flagName,
                    (string) ($payload['fromEnvironment'] ?? ''),
                    (string) ($payload['toEnvironment'] ?? ''),
                    $actorId,
                    $reason,
                ),
                ChangeType::Create => throw new \RuntimeException(
                    'Flag creation cannot be applied through a change request; create the flag first.',
                ),
            };
        });
    }

    private function applyMetadata(string $flagName, array $payload, string $actorId, ?string $reason): void
    {
        if (array_key_exists('owner', $payload)) {
            $this->writeService->setOwner($flagName, $payload['owner'] !== null ? (string) $payload['owner'] : null, $actorId, $reason);
        }

        if (array_key_exists('expiresAt', $payload)) {
            $expiresAt = $payload['expiresAt'] !== null ? new \DateTimeImmutable((string) $payload['expiresAt']) : null;
            $this->writeService->setExpiry($flagName, $expiresAt, $actorId, $reason);
        }
    }

    private function mustFind(string $id): ChangeRequest
    {
        $request = $this->storage->findById($id);

        if ($request === null) {
            throw new \DomainException(sprintf('Change request "%s" not found.', $id));
        }

        return $request;
    }

    private function emit(string $aggregateId, object $payload): void
    {
        $this->eventBus->dispatch(EventEnvelopeFactory::wrap($aggregateId, $payload, $this->clock->now()));
    }
}
