<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Registry\ProducerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:transports:list',
    description: 'List all registered transports and their associated producers'
)]
final class ListTransportsCommand extends Command
{
    public function __construct(
        private TransportRegistry $transportRegistry,
        private ProducerRegistry $producerRegistry
    ) {
        parent::__construct();
    }

    public function configure(): void {}

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $transports = $this->transportRegistry->all();

        if (empty($transports)) {
            $output->writeln('<comment>No transports registered.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d transport(s).</info>', count($transports)));

        foreach ($transports as $name => $config) {
            $output->writeln('');
            $output->writeln(sprintf('<info>▶ %s</info>', $name));

            $topic      = $config['subscription']['topic'] ?? '<fg=gray>—</>';
            $serializer = $config['serializer'] ?? 'json';
            $dsn        = $this->maskDsn($config['dsn'] ?? '');
            $hasSecurity = !empty($config['security']['sasl']) || !empty($config['security']['ssl']);

            $output->writeln(sprintf('  <fg=gray>Topic:</>       %s', $topic));
            $output->writeln(sprintf(
                '  <fg=gray>DSN:</>         %s%s',
                $dsn,
                $hasSecurity ? '  <fg=gray>[credentials redacted]</>' : '',
            ));
            $output->writeln(sprintf('  <fg=gray>Serializer:</>  %s', $serializer));

            $boundProducers = array_filter(
                $this->producerRegistry->all(),
                fn(array $p) => $p['transport'] === $name,
            );

            $output->writeln('');

            if (empty($boundProducers)) {
                $output->writeln('  <fg=gray>Producers:</>   none');
                continue;
            }

            $output->writeln(sprintf('  Producers (%d):', count($boundProducers)));

            foreach ($boundProducers as $producerName => $producer) {
                $outboxEnabled    = $producer['outbox']['enabled'] ?? true;
                $outboxDisplay    = $outboxEnabled ? '<info>outbox: on</>' : '<fg=yellow>outbox: off  [direct]</>';
                $comprEnabled     = $producer['compression']['enabled'] ?? false;
                $comprDisplay     = $comprEnabled
                    ? sprintf('  <fg=gray>compression: %s</>', $producer['compression']['type'] ?? 'snappy')
                    : '';

                $output->writeln(sprintf(
                    '    <fg=cyan>•</> <info>%s</>    %s%s',
                    $producerName,
                    $outboxDisplay,
                    $comprDisplay,
                ));

                $publishes = $producer['publishes'] ?? [];
                if (!empty($publishes)) {
                    $shortNames = array_map(fn(string $fqcn) => $this->shortName($fqcn), $publishes);
                    $output->writeln(sprintf(
                        '      <fg=gray>Publishes:</> %s',
                        implode(', ', $shortNames),
                    ));
                } else {
                    $output->writeln('      <fg=gray>Publishes:</> <fg=gray>—</>');
                }
            }
        }

        $output->writeln('');
        return Command::SUCCESS;
    }

    private function maskDsn(string $dsn): string
    {
        if ($dsn === '') {
            return '<fg=gray>—</>';
        }

        $parsed = parse_url($dsn);
        if ($parsed === false) {
            return $dsn;
        }

        $scheme = $parsed['scheme'] ?? '';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return sprintf('%s://%s%s', $scheme, $host, $port);
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
