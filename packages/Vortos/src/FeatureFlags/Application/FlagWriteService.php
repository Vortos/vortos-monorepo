<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Application;

use Vortos\Domain\Event\DomainEventLedger;
use Vortos\FeatureFlags\Domain\Flag;
use Vortos\FeatureFlags\Exception\FlagNotFoundException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Projection\FlagReadModelProjectorInterface;
use Vortos\FeatureFlags\Projection\NullFlagReadModelProjector;
use Vortos\FeatureFlags\RolloutSchedule;
use Vortos\FeatureFlags\Storage\FlagEnvironmentStateStorageInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\Messaging\Contract\EventBusInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * The single write boundary for feature flags (PHASE B / Block 7, extended Block 10).
 *
 * ## Why this is the only door
 *
 * Phase A let every CLI command call {@see FlagStorageInterface::save()} directly, so
 * mutations left no audit trail. This service is the *one* chokepoint every flag
 * mutation must pass through; it loads the {@see Flag} aggregate, applies the change
 * (which records a past-tense domain event), persists the new state, and publishes the
 * events. Routing all writes here is what makes the ledger/audit history complete — the
 * invariant is enforced by an architecture test (no `storage->save()/delete()` caller
 * outside this class) and by `@internal` on the storage mutators.
 *
 * ## Environment-aware writes (Block 10)
 *
 * Each mutation method reads the active environment from {@see FlagScopeContext} and
 * performs a dual-write:
 *   1. `FlagStorageInterface::save()` — the full definition row (keeps back-compat for
 *      any legacy reader that joins on the definition table).
 *   2. `FlagEnvironmentStateStorageInterface::save()` — the per-env mutable state, the
 *      authoritative source the {@see EnvironmentScopedFlagResolver} reads from.
 *
 * Both writes occur inside the same {@see UnitOfWorkInterface} transaction — they commit
 * together or roll back together.
 *
 * ## Transaction + event dispatch
 *
 * Flag mutations originate from the CLI and (later) the management API — *not* from a
 * {@see \Vortos\Cqrs\Command\CommandBus} dispatch — so this service brackets the
 * {@see DomainEventLedger} itself, exactly as the bus does: open a ledger scope inside a
 * {@see UnitOfWorkInterface} transaction, run the mutation (aggregate `recordEvent()`s
 * land in the ledger), persist, then drain the ledger to the {@see EventBusInterface}.
 * Save and outbox write are therefore atomic — both commit or both roll back. Scopes
 * nest safely: if a future caller invokes this from inside a command handler, only the
 * root scope drains, so events never double-publish.
 *
 * The hot read/eval path is untouched — it never goes through this service.
 */
final class FlagWriteService
{
    private readonly FlagReadModelProjectorInterface $projector;

    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly UnitOfWorkInterface $unitOfWork,
        private readonly EventBusInterface $eventBus,
        ?FlagReadModelProjectorInterface $projector = null,
        private readonly ?FlagEnvironmentStateStorageInterface $envStateStorage = null,
        private readonly ?FlagScopeContext $scope = null,
        private readonly ?ProjectContext $projectContext = null,
    ) {
        $this->projector = $projector ?? new NullFlagReadModelProjector();
    }

    public function create(FeatureFlag $flag, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($flag, $actorId, $reason): Flag {
            $env       = $this->activeEnv();
            $flagInEnv = $flag->withEnvironment($env)->withProject($this->activeProject());
            $aggregate = Flag::create($flagInEnv, $actorId, $reason);
            $this->storage->save($aggregate->state());
            $this->saveEnvState($aggregate->state());

            return $aggregate;
        });
    }

    /**
     * Enable a flag, optionally replacing its rule set in the same transaction (e.g. a
     * percentage rollout). Two events may be recorded (enabled + rules-changed); both
     * dispatch atomically.
     *
     * @param FlagRule[]|null $rules when non-null, the flag's rules are set to this exact list
     */
    public function enable(string $name, string $actorId, ?string $reason = null, ?array $rules = null): Flag
    {
        return $this->transactional(function () use ($name, $actorId, $reason, $rules): Flag {
            $flag = $this->load($name);
            $flag->enable($actorId, $reason);
            if ($rules !== null) {
                $flag->changeRules($rules, $actorId, $reason);
            }
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    public function disable(string $name, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->disable($actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    /**
     * @param FlagRule[] $rules
     */
    public function changeRules(string $name, array $rules, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $rules, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->changeRules($rules, $actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    /**
     * @param array<string,int>|null $variants
     */
    public function changeVariants(string $name, ?array $variants, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $variants, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->changeVariants($variants, $actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    public function schedule(string $name, ?RolloutSchedule $schedule, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $schedule, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->schedule($schedule, $actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    /**
     * Restore a flag to a prior {@see FeatureFlag} state (typically rebuilt from an audit
     * snapshot). Emits {@see \Vortos\FeatureFlags\Domain\Event\FlagRevertedEvent}.
     */
    public function revertTo(FeatureFlag $target, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($target, $actorId, $reason): Flag {
            $flag = $this->load($target->name);
            $flag->revertTo($target, $actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    /**
     * Archive a flag and remove its row. Emits {@see \Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent}
     * carrying the final snapshot *before* deletion, so the audit log keeps a complete
     * record even though the live row is hard-deleted (preserving the existing CLI
     * delete behaviour). Block 12 upgrades this to a soft-archive.
     */
    public function archiveAndDelete(string $name, string $actorId, ?string $reason = null): void
    {
        $this->transactional(function () use ($name, $actorId, $reason): null {
            $flag = $this->load($name);
            $flag->archive($actorId, $reason);
            $this->storage->delete($name);
            if ($this->envStateStorage !== null) {
                $this->envStateStorage->delete($flag->state()->id, $this->activeEnv());
            }

            return null;
        });
    }

    private function load(string $name): Flag
    {
        $definition = $this->storage->findByName($name);
        if ($definition === null) {
            throw FlagNotFoundException::forName($name);
        }

        $env = $this->activeEnv();

        // If env state storage is available and we have an env state row, compose the
        // FeatureFlag from definition + env state so the aggregate starts from the
        // correct env-specific values. Fall back to the legacy definition row for
        // 'production' (back-compat with Phase A/B flags that have no env state row yet).
        if ($this->envStateStorage !== null) {
            $envState = $this->envStateStorage->findForFlag($definition->id, $env);
            if ($envState !== null) {
                return Flag::reconstitute(FeatureFlag::compose($definition, $envState));
            }
        }

        return Flag::reconstitute($definition->withEnvironment($env));
    }

    public function changeLifecycle(string $name, FlagLifecycleState $lifecycle, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $lifecycle, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->changeLifecycle($lifecycle, $actorId, $reason);
            $this->storage->save($flag->state());
            $this->saveEnvState($flag->state());

            return $flag;
        });
    }

    public function setOwner(string $name, ?string $owner, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $owner, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->setOwner($owner, $actorId, $reason);
            $this->storage->save($flag->state());

            return $flag;
        });
    }

    public function setExpiry(string $name, ?\DateTimeImmutable $expiresAt, string $actorId, ?string $reason = null): Flag
    {
        return $this->transactional(function () use ($name, $expiresAt, $actorId, $reason): Flag {
            $flag = $this->load($name);
            $flag->setExpiry($expiresAt, $actorId, $reason);
            $this->storage->save($flag->state());

            return $flag;
        });
    }

    private function activeEnv(): string
    {
        return $this->scope?->environment() ?? FlagScopeContext::ENV_PRODUCTION;
    }

    private function activeProject(): string
    {
        return $this->projectContext?->projectId() ?? ProjectContext::DEFAULT_PROJECT;
    }

    /**
     * Persist the per-env mutable state alongside the full definition row (Block 10).
     * When env state storage is not wired (pre-Block-10 usage or tests), this is a no-op.
     */
    private function saveEnvState(FeatureFlag $flag): void
    {
        if ($this->envStateStorage === null) {
            return;
        }

        $this->envStateStorage->save(FlagEnvironmentState::fromFeatureFlag($flag, $flag->environment));
    }

    /**
     * Run $work inside a transaction with a ledger scope, draining recorded events to the
     * event bus at the root scope. Mirrors {@see \Vortos\Cqrs\Command\CommandBus} because
     * flag mutations are not dispatched through it.
     *
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
                            // Maintain the read models synchronously (idempotent upserts),
                            // then publish for in-process handlers / outbox / external consumers.
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
