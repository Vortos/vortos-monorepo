<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout\Contract;

interface LockoutStoreInterface
{
    public function incrementAttempts(string $type, string $value, int $windowSeconds): int;
    public function lock(string $type, string $value, int $durationSeconds): void;
    public function isLocked(string $type, string $value): bool;
    public function getRemainingTtl(string $type, string $value): int;
    public function clearAttempts(string $type, string $value): void;
}
