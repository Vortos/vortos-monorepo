<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Health;

use MongoDB\Client;
use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;

#[AsHealthCheck]
final class MongoHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return 'mongodb';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        try {
            $this->client->selectDatabase('admin')->command(['ping' => 1]);

            return new HealthResult($this->name(), true, $this->ms($start));
        } catch (\Throwable $e) {
            return new HealthResult($this->name(), false, $this->ms($start), $e->getMessage(), 'mongodb_unreachable');
        }
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
