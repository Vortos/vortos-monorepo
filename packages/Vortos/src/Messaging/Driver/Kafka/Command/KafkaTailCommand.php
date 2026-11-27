<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Command;

use Vortos\Messaging\Contract\PayloadSanitizerInterface;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaClientConfig;
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

        [$topic, $transport] = $this->resolveTransport($input, $transportName);

        if (($transport['dsn'] ?? '') === '') {
            $output->writeln('<error>No broker address found. Register the transport or use --brokers.</error>');
            return Command::FAILURE;
        }

        try {
            $conf = KafkaClientConfig::conf($transport, $transportName, [
                'group.id' => $groupId,
                'socket.timeout.ms' => '3000',
                'enable.auto.commit' => 'false',
                'auto.offset.reset' => $fromBeginning ? 'earliest' : 'latest',
            ]);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

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

    /**
     * The topic, and the transport whose connection (brokers and sign-in) the tail uses. --brokers replaces
     * only the broker list: a registered transport keeps its sign-in.
     *
     * @return array{string, array<string, mixed>} [topic, transportConfig]
     */
    private function resolveTransport(InputInterface $input, string $transportName): array
    {
        $explicit = (string) $input->getOption('brokers');

        try {
            $transport = $this->transports->get($transportName);
        } catch (\Throwable) {
            $brokers = $explicit !== '' ? $explicit : (getenv('KAFKA_BROKERS') ?: '');
            return [$transportName, ['dsn' => $brokers === '' ? '' : 'kafka://' . $brokers]];
        }

        $transport = is_array($transport) ? $transport : [];
        $topic = $transport['subscription']['topic'] ?? $transportName;
        if ($explicit !== '') {
            $dsn = (string) ($transport['dsn'] ?? '');
            $userinfo = preg_match('#^kafka://([^@/?]*@)#', $dsn, $m) === 1 ? $m[1] : '';
            $query = ($q = strpos($dsn, '?')) !== false ? substr($dsn, $q) : '';
            $transport['dsn'] = 'kafka://' . $userinfo . $explicit . $query;
        }

        return [$topic, $transport];
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
