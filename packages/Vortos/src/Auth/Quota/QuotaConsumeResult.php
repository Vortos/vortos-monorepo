<?php
declare(strict_types=1);

namespace Vortos\Auth\Quota;

final readonly class QuotaConsumeResult
{
    public function __construct(
        public bool $allowed,
        public int $current,
        public int $remaining,
        public int $resetAt,
    ) {}
}
