<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Health;

use Vortos\Foundation\Health\Attribute\AsHealthCheck;
use Vortos\Foundation\Health\Contract\HealthCheckInterface;
use Vortos\Foundation\Health\HealthResult;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaClientConfig;
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

        $clusters = $this->kafkaClusters();

        if ($clusters === []) {
            return new HealthResult($this->name(), true, 0.0);
        }

        if (!extension_loaded('rdkafka')) {
            return new HealthResult($this->name(), false, 0.0, 'rdkafka extension not loaded', 'kafka_extension_missing');
        }

        // Each distinct broker connection is checked the way its clients reach it, signed in as they are:
        // a check that connected anonymously would fail against a broker that requires sign-in, and report
        // a healthy cluster as down (or, worse, pass against a listener its clients no longer use).
        foreach ($clusters as $name => $transport) {
            try {
                $conf = KafkaClientConfig::conf($transport, $name, [
                    'socket.timeout.ms' => '3000',
                    'api.version.request.timeout.ms' => '3000',
                ]);
                $producer = new \RdKafka\Producer($conf);
                $producer->getMetadata(true, null, 3000);
            } catch (\Throwable $e) {
                return new HealthResult($this->name(), false, $this->ms($start), KafkaClientConfig::redact($e->getMessage()), 'kafka_unreachable');
            }
        }

        return new HealthResult($this->name(), true, $this->ms($start));
    }

    /**
     * One transport per distinct connection (brokers plus sign-in), keyed by transport name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function kafkaClusters(): array
    {
        $clusters = [];
        $seen = [];

        foreach ($this->transports->all() as $name => $transport) {
            if (!is_array($transport) || !str_starts_with((string) ($transport['dsn'] ?? ''), 'kafka://')) {
                continue;
            }
            $key = hash('sha256', serialize([$transport['dsn'], $transport['security'] ?? []]));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $clusters[(string) $name] = $transport;
            }
        }

        return $clusters;
    }

    private function ms(int $start): float
    {
        return round((hrtime(true) - $start) / 1_000_000, 2);
    }
}
