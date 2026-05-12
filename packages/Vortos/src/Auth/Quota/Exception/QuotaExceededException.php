<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota\Exception;

final class QuotaExceededException extends \RuntimeException
{
    public function __construct(
        public readonly string $quotaName,
        public readonly string $bucket,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $resetAt,
    ) {
        parent::__construct(sprintf('Quota "%s" exceeded for bucket "%s".', $quotaName, $bucket));
    }
}
