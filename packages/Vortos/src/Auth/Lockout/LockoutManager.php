<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

use Vortos\Auth\Lockout\Contract\LockoutStoreInterface;

/**
 * Manages account lockout state.
 * Used by LoginController to track failed attempts.
 */
final class LockoutManager
{
    public function __construct(
        private LockoutStoreInterface $store,
        private LockoutConfig $config,
    ) {}

    public function recordFailedAttempt(string $email, string $ip): void
    {
        $track = $this->config->trackBy;

        if ($track === LockoutTrack::Email || $track === LockoutTrack::Both) {
            $count = $this->store->incrementAttempts('email', $email, $this->config->lockDurationSeconds);
            if ($count >= $this->config->maxAttempts) {
                $this->store->lock('email', $email, $this->config->lockDurationSeconds);
            }
        }

        if ($track === LockoutTrack::Ip || $track === LockoutTrack::Both) {
            $count = $this->store->incrementAttempts('ip', $ip, $this->config->lockDurationSeconds);
            if ($count >= $this->config->maxAttempts) {
                $this->store->lock('ip', $ip, $this->config->lockDurationSeconds);
            }
        }
    }

    public function isLocked(string $email, string $ip): bool
    {
        $track = $this->config->trackBy;

        if ($track === LockoutTrack::Email || $track === LockoutTrack::Both) {
            if ($this->store->isLocked('email', $email)) return true;
        }

        if ($track === LockoutTrack::Ip || $track === LockoutTrack::Both) {
            if ($this->store->isLocked('ip', $ip)) return true;
        }

        return false;
    }

    public function getRemainingSeconds(string $email, string $ip): int
    {
        return max(
            $this->store->getRemainingTtl('email', $email),
            $this->store->getRemainingTtl('ip', $ip),
        );
    }

    public function clearLockout(string $email, string $ip): void
    {
        $this->store->clearAttempts('email', $email);
        $this->store->clearAttempts('ip', $ip);
    }

    public function getMessage(): string
    {
        return $this->config->message;
    }
}
