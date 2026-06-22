<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Application;

use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Domain\Flag;
use Vortos\FeatureFlags\Exception\FlagNotFoundException;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\Projection\FlagReadModelProjectorInterface;
use Vortos\FeatureFlags\Projection\NullFlagReadModelProjector;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * Copies a flag's per-environment state from one environment to another (Block 12).
 *
 * ## What promotion means
 *
 * "Promote staging → production" means: take the targeting rules, variants,
 * schedule, payload and enabled state that are active in `staging` and apply
 * them as-is to `production`. The flag definition (id, name, kind, valueType,
 * owner, lifecycle, project) is unchanged — only the env-state row for the
 * target environment is created/overwritten.
 *
 * ## Safety
 *
 * The operation is atomic (both the env-state write and the audit event commit
 * together). If the source env state row does not exist the promotion is
 * rejected — we never promote "nothing".
 *
 * ## Audit
 *
 * A {@see \Vortos\FeatureFlags\Domain\Event\FlagPromotedEvent} is recorded on
 * the source flag aggregate and drained to the event bus, giving the read model
 * and any webhook subscribers a typed event to react to.
 */
final class FlagPromotionService
{
    private readonly FlagReadModelProjectorInterface $projector;

    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagEnvironmentStateStorageInterface $envStateStorage,
        private readonly UnitOfWorkInterface $unitOfWork,
        private readonly EventBusInterface $eventBus,
        ?FlagReadModelProjectorInterface $projector = null,
    ) {
        $this->projector = $projector ?? new NullFlagReadModelProjector();
    }

    /**
     * Copy flag state from $fromEnv → $toEnv for the named flag.
     *
     * @throws FlagNotFoundException          if the flag definition does not exist
     * @throws \RuntimeException              if the source env has no state row
     */
    public function promote(
        string $flagName,
        string $fromEnv,
        string $toEnv,
        string $actorId,
        ?string $reason = null,
    ): void {
        $this->transactional(function () use ($flagName, $fromEnv, $toEnv, $actorId, $reason): null {
            $definition = $this->storage->findByName($flagName);
            if ($definition === null) {
                throw FlagNotFoundException::forName($flagName);
            }

            $sourceState = $this->envStateStorage->findForFlag($definition->id, $fromEnv);
            if ($sourceState === null) {
                throw new \RuntimeException(sprintf(
                    'Flag "%s" has no env state in environment "%s" — nothing to promote.',
                    $flagName,
                    $fromEnv,
                ));
            }

            // Clone the env state row, targeting the destination environment.
            $targetState = new FlagEnvironmentState(
                flagId:        $definition->id,
                environment:   $toEnv,
                enabled:       $sourceState->enabled,
                rules:         $sourceState->rules,
                variants:      $sourceState->variants,
                variantRules:  $sourceState->variantRules,
                schedule:      $sourceState->schedule,
                payload:       $sourceState->payload,
                requiredScope: $sourceState->requiredScope,
                prerequisites: $sourceState->prerequisites,
                updatedAt:     new \DateTimeImmutable(),
            );

            $this->envStateStorage->save($targetState);

            // Reconstitute aggregate for the source env to record the event.
            $sourceFlag = $definition->withEnvironment($fromEnv);
            $aggregate  = Flag::reconstitute($sourceFlag);
            $aggregate->recordPromotion($targetState, $toEnv, $actorId, $reason);

            return null;
        });
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        return $this->unitOfWork->run(function () use ($work): mixed {
            $ledger = DomainEventLedger::instance();
            $isRoot = $ledger->open();

            try {
                $result = $work();

                if ($isRoot) {
                    while ($ledger->hasPending()) {
                        foreach ($ledger->drain() as $envelope) {
                            $this->projector->apply($envelope);
                            $this->eventBus->dispatch($envelope);
                        }
                    }
                }

                return $result;
            } finally {
                $ledger->close();
            }
        });
    }
}
