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
                    $output->writeln(sprintf(
                        '      <fg=gray>Publishes:</> %s',
                        implode(', ', $this->describePublished($publishes)),
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

    /**
     * Renders a producer's published events.
     *
     * The event class is the **key** of this map and its metadata is the value
     * — the shape `AbstractProducerDefinition::publish()` builds. Reading the
     * values as class names instead fatals on the first producer that publishes
     * anything, which made this command unusable for answering the one question
     * it exists to answer: did my transport register, and under what wire name?
     *
     * The wire name is shown whenever it was pinned, because a mismatch between
     * the name in the code and the name on the wire is invisible until a
     * consumer silently receives nothing. Version is shown once it leaves 1, so
     * an upcast contract is not mistaken for the original.
     *
     * @param array<class-string, array{as?: string|null, version?: int}> $publishes
     *
     * @return list<string>
     */
    private function describePublished(array $publishes): array
    {
        $described = [];

        foreach ($publishes as $eventClass => $meta) {
            $label   = $this->shortName($eventClass);
            $wire    = $meta['as'] ?? null;
            $version = $meta['version'] ?? 1;

            if ($wire !== null && $wire !== '') {
                $label .= sprintf(' <fg=gray>→ %s</>', $wire);
            }

            if ($version > 1) {
                $label .= sprintf(' <fg=gray>v%d</>', $version);
            }

            $described[] = $label;
        }

        return $described;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
