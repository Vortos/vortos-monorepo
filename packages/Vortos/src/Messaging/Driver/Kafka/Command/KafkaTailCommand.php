<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Command;

use Vortos\Messaging\Contract\PayloadSanitizerInterface;
use Vortos\Messaging\Registry\TransportRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dev tool — stream Vortos events from a Kafka transport to the terminal.
 *
 * Uses a fixed consumer group (vortos-debug) that never commits offsets, so it
 * never interferes with application consumer offsets. Reads SASL/SSL config from
 * the registered transport definition so it works on authenticated clusters.
 * Payload is passed through PayloadSanitizerInterface before display so PII fields
 * are masked by the same rules applied to dead letter storage.
 */
#[AsCommand(
    name: 'vortos:kafka:tail',
    description: 'Stream Vortos events from a Kafka transport (dev tool)',
)]
final class KafkaTailCommand extends Command
{
    public function __construct(
        private readonly TransportRegistry $transports,
        private readonly PayloadSanitizerInterface $sanitizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::REQUIRED, 'Transport name (e.g. user.events)')
            ->addOption('brokers', null, InputOption::VALUE_REQUIRED, 'Broker list — overrides transport DSN')
            ->addOption('group-id', null, InputOption::VALUE_REQUIRED, 'Consumer group ID', 'vortos-debug')
            ->addOption('from-beginning', null, InputOption::VALUE_NONE, 'Start from the earliest available offset')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N messages (0 = unlimited)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!extension_loaded('rdkafka')) {
            $output->writeln('<error>The rdkafka PHP extension is not loaded.</error>');
            $output->writeln('Install: https://arnaud.le-blanc.net/php-rdkafka-doc/phpdoc/');
            return Command::FAILURE;
        }

        $transportName = $input->getArgument('transport');
        $fromBeginning = (bool) $input->getOption('from-beginning');
        $limit         = max(0, (int) $input->getOption('limit'));
        $groupId       = (string) $input->getOption('group-id');
        $verbose       = $output->isVerbose();

        [$brokers, $topic, $transport] = $this->resolveTransport($input, $transportName);

        if ($brokers === '') {
            $output->writeln('<error>No broker address found. Register the transport or use --brokers.</error>');
            return Command::FAILURE;
        }

        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('group.id', $groupId);
        $conf->set('socket.timeout.ms', '3000');
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', $fromBeginning ? 'earliest' : 'latest');

        $this->applySecurity($conf, $transport);

        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([$topic]);

        $output->writeln(sprintf(
            '<fg=gray>Tailing transport:</> <info>%s</info>  <fg=gray>(topic: %s, group: %s)</>',
            $transportName,
            $topic,
            $groupId,
        ));
        $output->writeln('<fg=gray>Waiting for messages... Ctrl+C to stop.</>');
        $output->writeln('');

        $running = true;
        $count   = 0;

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
            pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
        }

        while ($running) {
            $message = $consumer->consume(500);

            if ($message === null) {
                continue;
            }

            if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
                continue;
            }

            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $output->writeln(sprintf('<error>Kafka error: %s</error>', $message->errstr()));
                continue;
            }

            $count++;
            $headers = $message->headers ?? [];
            $rawPayload = $message->payload ?? '';

            try {
                $payload = $this->sanitizer->sanitize($rawPayload, $headers);
            } catch (\Throwable) {
                $payload = $rawPayload;
            }

            $time        = date('H:i:s');
            $eventShort  = $this->shortName($headers['payload_type'] ?? '');
            $aggId       = isset($headers['aggregate_id']) ? mb_substr($headers['aggregate_id'], 0, 22) . '...' : '—';
            $schema      = $headers['schema_version'] ?? '—';
            $correlation = $headers['correlation_id'] ?? '—';

            $output->writeln(sprintf(
                '<fg=gray>%s</>  <info>%-20s</>  <fg=gray>agg: %s  schema: %s  corr: %s</>',
                $time,
                $eventShort !== '' ? $eventShort : '(unknown)',
                $aggId,
                $schema,
                $correlation,
            ));

            if ($verbose) {
                $kafkaTs = $message->timestamp > 0
                    ? date('Y-m-d\TH:i:s\Z', (int) ($message->timestamp / 1000))
                    : '—';

                $output->writeln(sprintf(
                    '          <fg=gray>partition: %d   offset: %d   timestamp: %s</>',
                    $message->partition,
                    $message->offset,
                    $kafkaTs,
                ));

                if ($headers !== []) {
                    $output->writeln('          <fg=gray>headers:</>');
                    foreach ($headers as $key => $value) {
                        $output->writeln(sprintf('            <fg=gray>%-18s %s</>', $key . ':', $value));
                    }
                }

                $output->writeln('          <fg=gray>payload:</>');
                $output->writeln('            ' . $payload);
            } else {
                $output->writeln('          ' . $payload);
            }

            $output->writeln('');

            if ($limit > 0 && $count >= $limit) {
                break;
            }
        }

        $output->writeln(sprintf('<fg=gray>^C  Stopped. %d message(s) read.</>', $count));

        return Command::SUCCESS;
    }

    /** @return array{string, string, array} [brokers, topic, transportConfig] */
    private function resolveTransport(InputInterface $input, string $transportName): array
    {
        $explicit = (string) $input->getOption('brokers');

        try {
            $transport = $this->transports->get($transportName);
            $dsn       = is_array($transport) ? ($transport['dsn'] ?? '') : '';
            $topic     = is_array($transport) ? ($transport['subscription']['topic'] ?? $transportName) : $transportName;
            $brokers   = $explicit !== '' ? $explicit : str_replace('kafka://', '', $dsn);
            return [$brokers, $topic, is_array($transport) ? $transport : []];
        } catch (\Throwable) {
            return [$explicit !== '' ? $explicit : (getenv('KAFKA_BROKERS') ?: ''), $transportName, []];
        }
    }

    private function applySecurity(\RdKafka\Conf $conf, array $transport): void
    {
        $sasl = $transport['security']['sasl'] ?? [];
        $ssl  = $transport['security']['ssl'] ?? [];

        if (!empty($sasl) && !empty($sasl['username']) && !empty($sasl['password'])) {
            $conf->set('sasl.mechanisms', $sasl['mechanism'] ?? 'PLAIN');
            $conf->set('sasl.username', $sasl['username']);
            $conf->set('sasl.password', $sasl['password']);
            $conf->set('security.protocol', 'SASL_PLAINTEXT');
        }

        if (!empty($ssl)) {
            if (isset($ssl['ca_location']) && is_readable($ssl['ca_location'])) {
                $conf->set('ssl.ca.location', $ssl['ca_location']);
            }
            if (isset($ssl['certificate_location']) && is_readable($ssl['certificate_location'])) {
                $conf->set('ssl.certificate.location', $ssl['certificate_location']);
            }
            if (isset($ssl['key_location']) && is_readable($ssl['key_location'])) {
                $conf->set('ssl.key.location', $ssl['key_location']);
            }
            if (isset($ssl['key_password'])) {
                $conf->set('ssl.key.password', $ssl['key_password']);
            }
            if (isset($ssl['verify_peer'])) {
                $conf->set('enable.ssl.certificate.verification', $ssl['verify_peer'] ? 'true' : 'false');
            }

            $conf->set('security.protocol', !empty($sasl) ? 'SASL_SSL' : 'SSL');
        }
    }

    private function shortName(string $fqcn): string
    {
        if ($fqcn === '') {
            return '';
        }

        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
