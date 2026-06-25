<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed lead time for `cert_near_expiry`. */
final readonly class CertExpirySample implements SampleInterface
{
    public function __construct(
        public int $daysUntilExpiry,
        public string $subject,
    ) {}
}
