<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota;

enum QuotaPeriod: string
{
    case Hourly  = 'hourly';
    case Daily   = 'daily';
    case Monthly = 'monthly';
    case Total   = 'total';

    public function getPeriodKey(): string
    {
        return match($this) {
            self::Hourly  => date('Y-m-d-H'),
            self::Daily   => date('Y-m-d'),
            self::Monthly => date('Y-m'),
            self::Total   => 'total',
        };
    }

    public function getTtlSeconds(): int
    {
        return match($this) {
            self::Hourly  => 3600,
            self::Daily   => 86400,
            self::Monthly => 2678400, // 31 days
            self::Total   => 0,       // never expires
        };
    }
}
