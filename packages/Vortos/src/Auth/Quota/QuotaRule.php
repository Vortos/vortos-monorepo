<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota;

final readonly class QuotaRule
{
    public function __construct(
        public int $limit,
        public QuotaPeriod $period,
    ) {}

    public static function unlimited(): self
    {
        return new self(PHP_INT_MAX, QuotaPeriod::Monthly);
    }

    public function isUnlimited(): bool
    {
        return $this->limit === PHP_INT_MAX;
    }
}
