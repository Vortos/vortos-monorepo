<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Command;

use Fortizan\Tekton\Messaging\Registry\ConsumerRegistry;
use Fortizan\Tekton\Messaging\Registry\HandlerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists all registered consumers, their Kafka configuration, and their
 * registered event handlers with priorities and idempotency flags.
 * Useful for debugging handler registration and verifying MessagingConfig setup.
 */
final class ListConsumersCommand extends Command
{
    protected static string $defaultName = 'tekton:consumers:list';

    public function __construct(
        private HandlerRegistry $handlerRegistry,
        private ConsumerRegistry $consumerRegistry
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setDescription('List all registered consumers and their handlers');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $consumerNames = $this->consumerRegistry->all();

        if (empty($consumerNames)) {
            $output->writeln('<comment>No consumers registered.</comment>');
            return Command::SUCCESS;
        }

        foreach ($consumerNames as $consumerName => $definition) {
            $output->writeln("<info>Consumer: {$consumerName}</info>");

            if ($this->consumerRegistry->has($consumerName)) {
                $config = $this->consumerRegistry->get($consumerName)->toArray();
                $output->writeln("  Group ID: {$config['groupId']}");
                $output->writeln("  Parallelism: {$config['parallelism']}");
            }

            $eventHandlers = $this->handlerRegistry->allForConsumer($consumerName);

            foreach ($eventHandlers as $eventClass => $descriptors) {
                $output->writeln("  Event: {$eventClass}");

                foreach ($descriptors as $descriptor) {
                    $output->writeln(
                        "    - {$descriptor['handlerId']} (priority: {$descriptor['priority']}, idempotent: " . ($descriptor['idempotent'] ? 'yes' : 'no') . ")"
                    );
                }

                $output->writeln('');
            }
        }

        return Command::SUCCESS;
    }
}
