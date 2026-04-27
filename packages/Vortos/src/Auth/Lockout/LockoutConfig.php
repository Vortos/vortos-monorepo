<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

enum LockoutTrack: string
{
    case Email = 'email';
    case Ip    = 'ip';
    case Both  = 'both';
}

final class LockoutConfig
{
    public int $maxAttempts = 5;
    public int $lockDurationSeconds = 900;
    public LockoutTrack $trackBy = LockoutTrack::Email;
    public string $message = 'Account locked due to too many failed attempts. Try again later.';

    public function maxAttempts(int $attempts): static
    {
        $this->maxAttempts = $attempts;
        return $this;
    }

    public function lockDurationSeconds(int $seconds): static
    {
        $this->lockDurationSeconds = $seconds;
        return $this;
    }

    public function trackBy(LockoutTrack $track): static
    {
        $this->trackBy = $track;
        return $this;
    }

    public function message(string $message): static
    {
        $this->message = $message;
        return $this;
    }
}
