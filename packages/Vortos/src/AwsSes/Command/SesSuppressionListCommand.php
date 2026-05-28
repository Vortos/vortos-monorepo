<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\AwsSes\Contract\SuppressionListInterface;

/**
 * Lists locally suppressed email addresses stored in the suppression table.
 *
 * Usage:
 *   bin/console vortos:ses:suppression:list
 *   bin/console vortos:ses:suppression:list --limit=50 --offset=100
 */
#[AsCommand(
    name:        'vortos:ses:suppression:list',
    description: 'List locally suppressed email addresses.',
)]
final class SesSuppressionListCommand extends Command
{
    public function __construct(private readonly SuppressionListInterface $suppressionList)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit',  null, InputOption::VALUE_REQUIRED, 'Max rows to display', 100)
            ->addOption('offset', null, InputOption::VALUE_REQUIRED, 'Pagination offset',    0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $limit  = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');

        $entries = $this->suppressionList->list($limit, $offset);

        if ($entries === []) {
            $io->info('No suppressed addresses found.');
            return Command::SUCCESS;
        }

        $rows = array_map(
            static fn(array $e) => [
                $e['email_address']                          ?? '',
                $e['reason']                                 ?? '',
                $e['suppressed_at'] ?? $e['created_at']     ?? '',
            ],
            $entries,
        );

        $io->table(['Email Address', 'Reason', 'Suppressed At'], $rows);
        $io->text(sprintf('Showing %d entries (offset: %d).', count($rows), $offset));

        return Command::SUCCESS;
    }
}
