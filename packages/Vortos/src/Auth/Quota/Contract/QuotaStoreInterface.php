<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Contract;

use Vortos\Auth\Quota\QuotaConsumeResult;
use Vortos\Auth\Quota\QuotaPeriod;

interface QuotaStoreInterface
{
    public function consume(
        string $bucket,
        string $subjectId,
        string $quota,
        QuotaPeriod $period,
        int $limit,
        int $cost = 1,
    ): QuotaConsumeResult;

    public function compensate(
        string $bucket,
        string $subjectId,
        string $quota,
        QuotaPeriod $period,
        int $cost = 1,
    ): void;
}
