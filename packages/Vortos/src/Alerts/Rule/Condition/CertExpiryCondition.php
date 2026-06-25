<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

use InvalidArgumentException;
use Vortos\Alerts\Severity;

/** `cert_near_expiry` — proactive lead-time alerting; default 14-day warn / 1-day critical. */
final readonly class CertExpiryCondition implements AlertConditionInterface
{
    public function __construct(
        public int $leadDaysWarn = 14,
        public int $leadDaysCritical = 1,
    ) {
        if ($leadDaysWarn <= 0) {
            throw new InvalidArgumentException('CertExpiryCondition leadDaysWarn must be > 0.');
        }
        if ($leadDaysCritical <= 0) {
            throw new InvalidArgumentException('CertExpiryCondition leadDaysCritical must be > 0.');
        }
        if ($leadDaysCritical >= $leadDaysWarn) {
            throw new InvalidArgumentException('CertExpiryCondition leadDaysCritical must be < leadDaysWarn.');
        }
    }

    public function severityFor(int $daysUntilExpiry): ?Severity
    {
        if ($daysUntilExpiry <= $this->leadDaysCritical) {
            return Severity::Critical;
        }
        if ($daysUntilExpiry <= $this->leadDaysWarn) {
            return Severity::Warning;
        }

        return null;
    }
}
