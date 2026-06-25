<?php
declare(strict_types=1);

namespace Vortos\Auth\Contract;

interface TokenFreshnessGuardInterface
{
    /**
     * @return null|string Null if the token is fresh, or a rejection reason string.
     */
    public function check(string $userId, int $authzVersion, int $issuedAt): ?string;
}
