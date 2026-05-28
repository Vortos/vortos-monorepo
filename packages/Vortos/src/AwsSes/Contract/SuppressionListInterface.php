<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Contract;

use Vortos\AwsSes\Suppression\SuppressionEntry;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;

interface SuppressionListInterface
{
    public function isSuppressed(EmailAddress $address): bool;

    public function suppress(EmailAddress $address, SuppressionReason $reason): void;

    public function unsuppress(EmailAddress $address): void;

    /**
     * @return SuppressionEntry[]
     */
    public function list(int $limit = 100, int $offset = 0): array;
}
