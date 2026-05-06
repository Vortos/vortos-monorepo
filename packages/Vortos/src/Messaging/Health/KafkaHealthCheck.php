<?php

declare(strict_types=1);

namespace Vortos\Messaging\Health;

use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;
use Vortos\Messaging\Registry\TransportRegistry;

#[AsHealthCheck(critical: false)]
final class KafkaHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly TransportRegistry $transports) {}

    public function name(): string
    {
        return 'kafka';
    }

    public function check(): HealthResult
    {
        $start = hrtime(true);

        $brokers = $this->resolveKafkaBrokers();

        if ($brokers === []) {
            return new HealthResult($this->name(), true, 0.0);
        }

        if (!extension_loaded('rdkafka')) {
            return new HealthResult($this->name(), false, 0.0, 'rdkafka extension not loaded', 'kafka_extension_missing');
        }

        try {
            $conf = new \RdKafka\Conf();
            $conf->set('metadata.broker.list', implode(',', $brokers));
            $conf->set('socket.timeout.ms', '3000');
            $conf->set('api.version.request.timeout.ms', '3000');

            $producer = new \RdKafka\Producer($conf);
            $producer->getMetadata(true, null, 3000);

            return new HealthResult($this->name(), true, $this->ms($start));
        } catch (\Throwable $e) {
            return new HealthResult($this->name(), false, $this->ms($start), $e->getMessage(), 'kafka_unreachable');
        }
    }

    /** @return string[] */
    private function resolveKafkaBrokers(): array
    {
        $brokers = [];

        foreach ($this->transports->all() as $transport) {
            $dsn = is_array($transport) ? ($transport['dsn'] ?? '') : '';

            if (str_starts_with($dsn, 'kafka://')) {
                $brokers[] = str_replace('kafka://', '', $dsn);
            }
        }

        return array_unique($brokers);
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
