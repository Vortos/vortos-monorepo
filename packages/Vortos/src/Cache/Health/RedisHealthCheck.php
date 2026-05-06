<?php

declare(strict_types=1);

namespace Vortos\Cache\Health;

use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;

#[AsHealthCheck]
final class RedisHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        try {
            $pong = $this->redis->ping();
            $ok   = $pong === '+PONG' || $pong === true;

            return new HealthResult(
                $this->name(),
                $ok,
                $this->ms($start),
                $ok ? null : 'Unexpected PING response',
                $ok ? null : 'redis_unexpected_response',
            );
        } catch (\Throwable $e) {
            return new HealthResult($this->name(), false, $this->ms($start), $e->getMessage(), 'redis_unreachable');
        }
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
