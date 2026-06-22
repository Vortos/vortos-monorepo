<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Http\RateLimit;

use Vortos\Cache\Contract\AtomicCacheInterface;
use Vortos\FeatureFlags\Http\Exception\TooManyRequestsException;

class FlagRateLimitService
{
    public function __construct(
        private readonly ?AtomicCacheInterface $cache,
    ) {}

    /** 120 req / 60s per userId. */
    public function checkManagement(string $userId): void
    {
        $this->check('mgmt', $userId, 120);
    }

    /** 1000 req / 60s per SDK key id. */
    public function checkEval(string $keyId): void
    {
        $this->check('eval', $keyId, 1000);
    }

    /** 500 req / 60s per SDK key id. */
    public function checkExposure(string $keyId): void
    {
        $this->check('exposure', $keyId, 500);
    }

    private function check(string $bucket, string $subject, int $limit): void
    {
        if ($this->cache === null) {
            return;
        }

        $window = (int) (time() / 60);
        $key    = 'RateLimit:' . $bucket . ':' . $subject . ':' . $window;

        $current = $this->cache->get($key);

        if ($current === null) {
            // Seed the counter atomically; if another request wins, we read their value next.
            $this->cache->setNx($key, 1, 120);
            $current = (int) ($this->cache->get($key) ?? 1);
        } else {
            $current = (int) $current + 1;
            $this->cache->set($key, $current, 120);
        }

        if ($current > $limit) {
            throw new TooManyRequestsException(60);
        }
    }
}
