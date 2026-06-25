<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

use Psr\Log\LoggerInterface;
use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;
use Vortos\Auth\Lockout\Exception\LockoutUnavailableException;

final class LockoutManager
{
    public function __construct(
        private LockoutStoreInterface $store,
        private LockoutConfig $config,
        private LockoutKeyNormalizer $normalizer,
        private LockoutFailureMode $failureMode = LockoutFailureMode::FailClosed,
        private ?LoggerInterface $logger = null,
    ) {}

    public function recordFailedAttempt(string $email, string $ip): void
    {
        $track = $this->config->trackBy;
        $emailKey = $this->normalizer->normalize('email', $email);
        $ipKey = $this->normalizer->normalize('ip', $ip);

        if ($track === LockoutTrack::Email || $track === LockoutTrack::Both) {
            $count = $this->store->incrementAttempts('email', $emailKey, $this->config->lockDurationSeconds);
            if ($count >= $this->config->maxAttempts && $count > 0) {
                $this->store->lock('email', $emailKey, $this->config->lockDurationSeconds);
            }
            if ($count === -1) {
                $this->logger?->error('lockout.store_unavailable', [
                    'operation' => 'incrementAttempts',
                    'type' => 'email',
                ]);
            }
        }

        if ($track === LockoutTrack::Ip || $track === LockoutTrack::Both) {
            $count = $this->store->incrementAttempts('ip', $ipKey, $this->config->lockDurationSeconds);
            if ($count >= $this->config->maxAttempts && $count > 0) {
                $this->store->lock('ip', $ipKey, $this->config->lockDurationSeconds);
            }
            if ($count === -1) {
                $this->logger?->error('lockout.store_unavailable', [
                    'operation' => 'incrementAttempts',
                    'type' => 'ip',
                ]);
            }
        }
    }

    /**
     * @throws LockoutUnavailableException When store is unavailable and failure mode is FailClosed.
     */
    public function check(string $email, string $ip): LockoutResult
    {
        $track = $this->config->trackBy;
        $emailKey = $this->normalizer->normalize('email', $email);
        $ipKey = $this->normalizer->normalize('ip', $ip);

        if ($track === LockoutTrack::Email || $track === LockoutTrack::Both) {
            $result = $this->store->isLocked('email', $emailKey);
            if ($result->unavailable) {
                return $this->handleUnavailableResult('check', 'email');
            }
            if ($result->locked) {
                if ($track === LockoutTrack::Both) {
                    $ipAttempts = $this->store->getAttemptCount('ip', $ipKey);
                    if ($ipAttempts === 0) {
                        return $this->computeBackoff('email', $emailKey);
                    }
                }
                $remaining = $this->store->getRemainingTtl('email', $emailKey);
                return LockoutResult::locked($remaining);
            }

            $backoff = $this->computeBackoff('email', $emailKey);
            if ($backoff->shouldDelay()) {
                return $backoff;
            }
        }

        if ($track === LockoutTrack::Ip || $track === LockoutTrack::Both) {
            $result = $this->store->isLocked('ip', $ipKey);
            if ($result->unavailable) {
                return $this->handleUnavailableResult('check', 'ip');
            }
            if ($result->locked) {
                $remaining = $this->store->getRemainingTtl('ip', $ipKey);
                return LockoutResult::locked($remaining);
            }

            $backoff = $this->computeBackoff('ip', $ipKey);
            if ($backoff->shouldDelay()) {
                return $backoff;
            }
        }

        return LockoutResult::clear();
    }

    /**
     * @deprecated Use check() which returns LockoutResult with backoff information.
     * @throws LockoutUnavailableException When store is unavailable and failure mode is FailClosed.
     */
    public function isLocked(string $email, string $ip): bool
    {
        $result = $this->check($email, $ip);
        return $result->locked;
    }

    public function getRemainingSeconds(string $email, string $ip): int
    {
        $emailKey = $this->normalizer->normalize('email', $email);
        $ipKey = $this->normalizer->normalize('ip', $ip);

        return max(
            $this->store->getRemainingTtl('email', $emailKey),
            $this->store->getRemainingTtl('ip', $ipKey),
        );
    }

    public function clearLockout(string $email, string $ip): void
    {
        $emailKey = $this->normalizer->normalize('email', $email);
        $ipKey = $this->normalizer->normalize('ip', $ip);

        $this->store->clearAttempts('email', $emailKey);
        $this->store->clearAttempts('ip', $ipKey);
    }

    public function getMessage(): string
    {
        return $this->config->message;
    }

    private function computeBackoff(string $type, string $normalizedKey): LockoutResult
    {
        $attempts = $this->store->getAttemptCount($type, $normalizedKey);

        if ($attempts <= 0) {
            return LockoutResult::clear();
        }

        if ($attempts < 3) {
            return LockoutResult::clear();
        }

        $delay = (int) min(
            $this->config->backoffMaxSeconds,
            $this->config->backoffBaseSeconds * (2 ** ($attempts - 3)),
        );

        return $delay > 0 ? LockoutResult::backoff($delay) : LockoutResult::clear();
    }

    /**
     * @throws LockoutUnavailableException
     */
    private function handleUnavailableResult(string $operation, string $type): LockoutResult
    {
        $this->logger?->error('lockout.store_unavailable', [
            'operation' => $operation,
            'type' => $type,
            'failure_mode' => $this->failureMode->value,
        ]);

        if ($this->failureMode === LockoutFailureMode::FailClosed) {
            throw new LockoutUnavailableException(
                'Lockout store is unavailable. Login denied for safety.',
            );
        }

        return LockoutResult::clear();
    }
}
