<?php
declare(strict_types=1);

namespace Vortos\Auth\RateLimit\Contract;

use Vortos\Auth\RateLimit\Exception\RateLimitStoreUnavailableException;

interface RateLimitStoreInterface
{
    /** @throws RateLimitStoreUnavailableException */
    public function increment(string $key, int $windowSeconds): int;

    /** @throws RateLimitStoreUnavailableException */
    public function getTtl(string $key): int;

    /** @throws RateLimitStoreUnavailableException */
    public function reset(string $key): void;
}
