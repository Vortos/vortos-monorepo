<?php
declare(strict_types=1);

namespace Vortos\Auth\Lockout\Contract;

use Vortos\Auth\Lockout\LockoutCheckResult;

interface LockoutStoreInterface
{
    public function incrementAttempts(string $type, string $value, int $windowSeconds): int;
    public function lock(string $type, string $value, int $durationSeconds): void;
    public function isLocked(string $type, string $value): LockoutCheckResult;
    public function getAttemptCount(string $type, string $value): int;
    public function getRemainingTtl(string $type, string $value): int;
    public function clearAttempts(string $type, string $value): void;
}
