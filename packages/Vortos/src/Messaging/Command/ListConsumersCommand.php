<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\HandlerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:consumers:list',
    description: 'List all registered consumers and their handlers'
)]
final class ListConsumersCommand extends Command
{
    public function __construct(
        private HandlerRegistry $handlerRegistry,
        private ConsumerRegistry $consumerRegistry
    ) {
        parent::__construct();
    }

    public function configure(): void {}

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $consumers = $this->consumerRegistry->all();

        if (empty($consumers)) {
            $output->writeln('<comment>No consumers registered.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d consumer(s).</info>', count($consumers)));
        $output->writeln('');
        $output->writeln('  <fg=gray>idempotent: yes</>  handler skips Redis dedup — runs on every delivery');
        $output->writeln('  <fg=gray>idempotent: no </>  Redis dedup active — duplicate deliveries skip the handler');

        foreach ($consumers as $consumerName => $config) {
            $output->writeln('');

            $inProcess = $config['inProcess'] ?? false;
            $badge = $inProcess
                ? '<fg=yellow>[in-process]</>'
                : '<fg=cyan>[kafka]</>';

            $output->writeln(sprintf('<info>▶ %s</info>  %s', $consumerName, $badge));

            $group     = $config['groupId'] ?: '<fg=gray>—</>';
            $transport = $inProcess ? '<fg=gray>—</>' : $config['transport'];
            $ttl       = isset($config['idempotencyTtl']) ? $config['idempotencyTtl'] . 's' : '<fg=gray>—</>';

            $output->writeln(sprintf('  <fg=gray>Group:</>       %s', $group));
            $output->writeln(sprintf('  <fg=gray>Transport:</>   %s', $transport));
            $output->writeln(sprintf(
                '  <fg=gray>Config:</>      parallelism: %d   batch: %d   ttl: %s',
                $config['parallelism'],
                $config['batchSize'],
                $ttl,
            ));

            $retry = $config['retry'] ?? [];
            if (!empty($retry)) {
                $retrySummary = sprintf(
                    '%s · %d attempts · %dms initial · %dms cap',
                    $retry['backoff'],
                    $retry['maxAttempts'],
                    $retry['initialDelay'],
                    $retry['maxDelay'],
                );
                $output->writeln(sprintf('  <fg=gray>Retry:</>       %s', $retrySummary));
            } else {
                $output->writeln('  <fg=gray>Retry:</>       <fg=gray>—</>');
            }

            $dlq = $config['dlq'] ?? '';
            $dlqDisplay = $dlq ? sprintf('<fg=yellow>%s</>', $dlq) : '<fg=gray>—</>';
            $output->writeln(sprintf('  <fg=gray>DLQ:</>         %s', $dlqDisplay));

            $eventHandlers = $this->handlerRegistry->allForConsumer($consumerName);

            if (empty($eventHandlers)) {
                $output->writeln('');
                $output->writeln('  <comment>No handlers registered.</comment>');
                continue;
            }

            $totalHandlers = array_sum(array_map('count', $eventHandlers));
            $output->writeln('');
            $output->writeln(sprintf('  Handlers (%d):', $totalHandlers));

            foreach ($eventHandlers as $eventClass => $descriptors) {
                $shortName = $this->shortName($eventClass);
                $output->writeln(sprintf(
                    '    <fg=cyan>%s</>  <fg=gray>(%s)</>',
                    $shortName,
                    $eventClass,
                ));

                $last = array_key_last($descriptors);
                foreach ($descriptors as $i => $descriptor) {
                    $tree = ($i === $last) ? '└' : '├';

                    $idempotentDisplay = $descriptor['idempotent']
                        ? '<fg=yellow>idempotent: yes</>'
                        : '<info>idempotent: no </>';

                    $output->writeln(sprintf(
                        '      <fg=gray>%s</> %-55s priority: %3d   %s',
                        $tree,
                        $descriptor['handlerId'],
                        $descriptor['priority'],
                        $idempotentDisplay,
                    ));
                }

                $output->writeln('');
            }
        }

        return Command::SUCCESS;
    }

    private function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }
}
