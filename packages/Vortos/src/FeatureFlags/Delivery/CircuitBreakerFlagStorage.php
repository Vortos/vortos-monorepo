<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Delivery;

use Psr\Log\LoggerInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Circuit-breaker decorator around flag storage (Block 16).
 *
 * When the inner storage (DB/Redis) fails consecutively, the breaker opens and serves
 * the last-known-good snapshot from memory — the SDK never sees a blank flag set or a
 * 5xx. After a cooldown the breaker half-opens and probes; on success it closes.
 *
 * Write-through: save/delete always attempt the inner storage and re-throw on failure
 * (management mutations must not be silently dropped).
 */
final class CircuitBreakerFlagStorage implements FlagStorageInterface
{
    private CircuitState $state = CircuitState::Closed;
    private int $consecutiveFailures = 0;
    private float $openedAt = 0.0;

    /** @var FeatureFlag[]|null last successful snapshot */
    private ?array $lastKnownGood = null;

    public function __construct(
        private readonly FlagStorageInterface $inner,
        private readonly int $failureThreshold = 3,
        private readonly float $cooldownSeconds = 10.0,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function findAll(): array
    {
        if ($this->state === CircuitState::Open) {
            if ($this->cooldownElapsed()) {
                $this->state = CircuitState::HalfOpen;
            } else {
                return $this->lastKnownGood ?? [];
            }
        }

        try {
            $flags = $this->inner->findAll();
            $this->onSuccess($flags);
            return $flags;
        } catch (\Throwable $e) {
            $this->onFailure($e);
            return $this->lastKnownGood ?? [];
        }
    }

    public function findByName(string $name): ?FeatureFlag
    {
        if ($this->state === CircuitState::Open && !$this->cooldownElapsed()) {
            return $this->findInSnapshot($name);
        }

        try {
            if ($this->state === CircuitState::Open) {
                $this->state = CircuitState::HalfOpen;
            }
            $result = $this->inner->findByName($name);
            if ($this->state === CircuitState::HalfOpen) {
                $this->state = CircuitState::Closed;
                $this->consecutiveFailures = 0;
            }
            return $result;
        } catch (\Throwable $e) {
            $this->onFailure($e);
            return $this->findInSnapshot($name);
        }
    }

    public function save(FeatureFlag $flag): void
    {
        $this->inner->save($flag);
    }

    public function delete(string $name): void
    {
        $this->inner->delete($name);
    }

    public function circuitState(): CircuitState
    {
        return $this->state;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    private function onSuccess(array $flags): void
    {
        $this->lastKnownGood       = $flags;
        $this->consecutiveFailures = 0;

        if ($this->state !== CircuitState::Closed) {
            $this->logger?->info('Circuit breaker closed — storage recovered.');
            $this->state = CircuitState::Closed;
        }
    }

    private function onFailure(\Throwable $e): void
    {
        $this->consecutiveFailures++;

        if ($this->consecutiveFailures >= $this->failureThreshold && $this->state !== CircuitState::Open) {
            $this->state    = CircuitState::Open;
            $this->openedAt = $this->now();
            $this->logger?->warning('Circuit breaker opened — serving last-known-good.', [
                'failures' => $this->consecutiveFailures,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function cooldownElapsed(): bool
    {
        return ($this->now() - $this->openedAt) >= $this->cooldownSeconds;
    }

    private function findInSnapshot(string $name): ?FeatureFlag
    {
        if ($this->lastKnownGood === null) {
            return null;
        }

        foreach ($this->lastKnownGood as $flag) {
            if ($flag->name === $name) {
                return $flag;
            }
        }

        return null;
    }

    private function now(): float
    {
        return microtime(true);
    }
}
