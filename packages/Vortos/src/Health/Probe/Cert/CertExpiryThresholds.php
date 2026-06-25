<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Cert;

use InvalidArgumentException;
use Vortos\Health\Probe\ProbeStatus;

/**
 * Graduated lead-time thresholds (§3 cert) — renewal is proactive (14/7-day warn),
 * never a 3am page; only the final day is critical.
 */
final readonly class CertExpiryThresholds
{
    public function __construct(
        public int $warnDays = 14,
        public int $secondWarnDays = 7,
        public int $criticalDays = 1,
    ) {
        if ($criticalDays <= 0) {
            throw new InvalidArgumentException('CertExpiryThresholds criticalDays must be > 0.');
        }
        if ($secondWarnDays <= $criticalDays) {
            throw new InvalidArgumentException('CertExpiryThresholds secondWarnDays must be > criticalDays.');
        }
        if ($warnDays <= $secondWarnDays) {
            throw new InvalidArgumentException('CertExpiryThresholds warnDays must be > secondWarnDays.');
        }
    }

    public function statusFor(int $daysUntilExpiry): ProbeStatus
    {
        if ($daysUntilExpiry <= $this->criticalDays) {
            return ProbeStatus::Fail;
        }

        if ($daysUntilExpiry <= $this->warnDays) {
            return ProbeStatus::Warn;
        }

        return ProbeStatus::Pass;
    }
}
