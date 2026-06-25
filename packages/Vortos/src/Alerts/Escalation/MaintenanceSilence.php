<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A declared, time-boxed, auto-expiring, audited silence (§3.5, improvement #5). A
 * silence can never be open-ended: the constructor enforces both `expiresAt > startsAt`
 * and a hard maximum duration, so an attacker/operator can't permanently blind
 * monitoring.
 */
final readonly class MaintenanceSilence
{
    public const MAX_DURATION_SECONDS = 30 * 86400;

    /** `ruleId` of `'*'` matches every rule. */
    public function __construct(
        public string $id,
        public string $ruleId,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $expiresAt,
        public string $createdBy,
        public string $reason,
    ) {
        if ($id === '' || $ruleId === '' || $createdBy === '' || $reason === '') {
            throw new InvalidArgumentException('MaintenanceSilence id/ruleId/createdBy/reason must not be empty.');
        }
        if ($expiresAt <= $startsAt) {
            throw new InvalidArgumentException('MaintenanceSilence expiresAt must be after startsAt.');
        }
        if (($expiresAt->getTimestamp() - $startsAt->getTimestamp()) > self::MAX_DURATION_SECONDS) {
            throw new InvalidArgumentException(sprintf(
                'MaintenanceSilence duration must not exceed %d seconds (%d days).',
                self::MAX_DURATION_SECONDS,
                intdiv(self::MAX_DURATION_SECONDS, 86400),
            ));
        }
    }

    public function coversRule(string $ruleId): bool
    {
        return $this->ruleId === '*' || $this->ruleId === $ruleId;
    }

    public function isActiveAt(DateTimeImmutable $now): bool
    {
        return $now >= $this->startsAt && $now < $this->expiresAt;
    }
}
