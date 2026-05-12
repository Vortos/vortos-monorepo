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
        return $this->getPeriodKeyAt(time());
    }

    public function getPeriodKeyAt(int $timestamp): string
    {
        return match($this) {
            self::Hourly  => gmdate('Y-m-d-H', $timestamp),
            self::Daily   => gmdate('Y-m-d', $timestamp),
            self::Monthly => gmdate('Y-m', $timestamp),
            self::Total   => 'total',
        };
    }

    public function getResetAtTimestampAt(int $timestamp): int
    {
        if ($this === self::Total) {
            return 0;
        }

        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));

        return match ($this) {
            self::Hourly => ((int) floor($timestamp / 3600) + 1) * 3600,
            self::Daily => $date->modify('tomorrow')->setTime(0, 0)->getTimestamp(),
            self::Monthly => $date->modify('first day of next month')->setTime(0, 0)->getTimestamp(),
            self::Total => 0,
        };
    }

    public function getTtlSeconds(): int
    {
        return match($this) {
            self::Hourly  => 3600,
            self::Daily   => 86400,
            self::Monthly => 2678400,
            self::Total   => 0,
        };
    }
}
