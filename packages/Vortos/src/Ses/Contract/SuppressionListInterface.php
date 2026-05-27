<?php

declare(strict_types=1);

namespace Vortos\Ses\Contract;

use Vortos\Ses\Suppression\SuppressionEntry;
use Vortos\Ses\Suppression\SuppressionReason;
use Vortos\Ses\ValueObject\EmailAddress;

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
