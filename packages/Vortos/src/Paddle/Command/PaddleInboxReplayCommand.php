<?php

declare(strict_types=1);

namespace Vortos\Paddle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Paddle\Inbox\PaddleInboxReplayStoreInterface;

#[AsCommand(
    name: 'vortos:paddle:inbox:replay',
    description: 'Revive dead Paddle webhook inbox rows so the worker retries them.',
)]
final class PaddleInboxReplayCommand extends Command
{
    public function __construct(
        private readonly PaddleInboxReplayStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Replay a single inbox row by id.')
            ->addOption('event-type', null, InputOption::VALUE_REQUIRED, 'Replay only rows of this Paddle event type.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows to replay.', 100)
            ->addOption('list', null, InputOption::VALUE_NONE, 'List dead rows without replaying.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $id        = $input->getOption('id') !== null ? (int) $input->getOption('id') : null;
        $eventType = $input->getOption('event-type');
        $limit     = max(1, (int) $input->getOption('limit'));

        if ((bool) $input->getOption('list')) {
            $rows = $this->store->listDead($limit, $id, $eventType);

            if ($rows === []) {
                $io->success('No dead Paddle webhook inbox rows.');
                return Command::SUCCESS;
            }

            $io->table(
                ['id', 'event_id', 'event_type', 'attempts', 'last_error', 'received_at'],
                array_map(static fn(array $r) => [
                    $r['id'],
                    $r['event_id'],
                    $r['event_type'],
                    $r['attempts'],
                    mb_substr((string) $r['last_error'], 0, 80),
                    $r['received_at'],
                ], $rows),
            );

            return Command::SUCCESS;
        }

        $dead = $this->store->countDead($id, $eventType);

        if ($dead === 0) {
            $io->success('No dead Paddle webhook inbox rows to replay.');
            return Command::SUCCESS;
        }

        $revived = $this->store->replayDead($limit, $id, $eventType);
        $io->success(sprintf('Revived %d of %d dead inbox row%s — the worker will retry them.', $revived, $dead, $dead === 1 ? '' : 's'));

        return Command::SUCCESS;
    }
}
