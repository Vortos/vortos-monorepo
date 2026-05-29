<?php

declare(strict_types=1);

namespace Vortos\Paddle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Paddle\Webhook\WebhookIdempotencyStore;

#[AsCommand(
    name: 'vortos:paddle:webhook:idempotency:prune',
    description: 'Remove expired Paddle webhook idempotency records.',
)]
final class PaddleWebhookIdempotencyPruneCommand extends Command
{
    public function __construct(private readonly WebhookIdempotencyStore $store)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deleted = $this->store->pruneExpired();
        $output->writeln(sprintf('Pruned %d expired idempotency records.', $deleted));
        return Command::SUCCESS;
    }
}
