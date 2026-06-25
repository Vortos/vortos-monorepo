<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain;

use InvalidArgumentException;

final readonly class ObjectLockPolicy
{
    public function __construct(
        public string $mode,
        public int $retentionDays,
        public bool $legalHold = false,
    ) {
        if (!in_array($mode, ['compliance', 'governance'], true)) {
            throw new InvalidArgumentException(sprintf('Object lock mode must be compliance or governance, got "%s".', $mode));
        }
        if ($retentionDays < 1) {
            throw new InvalidArgumentException('Retention days must be >= 1.');
        }
    }

    public function isWithinRetention(\DateTimeImmutable $createdAt, \DateTimeImmutable $now): bool
    {
        $retentionEnd = $createdAt->modify(sprintf('+%d days', $this->retentionDays));

        return $now < $retentionEnd || $this->legalHold;
    }
}
