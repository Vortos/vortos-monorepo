<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout;

final readonly class LockoutResult
{
    private function __construct(
        public bool $locked,
        public int $backoffSeconds,
        public bool $unavailable,
    ) {}

    public static function locked(int $remainingSeconds = 0): self
    {
        return new self(true, $remainingSeconds, false);
    }

    public static function backoff(int $seconds): self
    {
        return new self(false, $seconds, false);
    }

    public static function clear(): self
    {
        return new self(false, 0, false);
    }

    public static function unavailable(): self
    {
        return new self(false, 0, true);
    }

    public function shouldDelay(): bool
    {
        return $this->backoffSeconds > 0;
    }
}
