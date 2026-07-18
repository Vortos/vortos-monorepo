<?php

declare(strict_types=1);

namespace Vortos\Search\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Search\Backfill\SearchIndexRebuilder;

/**
 * Rebuilds the search index from the registered backfill sources — for first roll-out, after a
 * mapping/relevance change, or as a drift safety-net. Idempotent (upsert), so re-running only
 * refreshes rows.
 *
 *   php bin/console vortos:search:rebuild                          # all types, all tenants
 *   php bin/console vortos:search:rebuild --type=application       # one type
 *   php bin/console vortos:search:rebuild --tenant=<id> --fresh    # purge+rebuild one tenant
 */
#[AsCommand(name: 'vortos:search:rebuild', description: 'Rebuild the search index from the registered backfill sources.')]
final class SearchRebuildCommand extends Command
{
    public function __construct(private readonly SearchIndexRebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Rebuild only this doc type.');
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Restrict to one tenant/org id.');
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Purge existing rows first (requires --tenant).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $type     = $input->getOption('type');
        $tenantId = $input->getOption('tenant');
        $fresh    = (bool) $input->getOption('fresh');

        if ($this->rebuilder->types() === []) {
            $io->warning('No search backfill sources are registered — nothing to rebuild.');
            return Command::SUCCESS;
        }

        try {
            if ($type !== null) {
                $count = $this->rebuilder->rebuildType($type, $tenantId, $fresh);
                $io->success(sprintf('Indexed %d "%s" document(s).', $count, $type));
                return Command::SUCCESS;
            }

            $totals = $this->rebuilder->rebuildAll($tenantId, $fresh);
            foreach ($totals as $t => $count) {
                $io->writeln(sprintf('· %-24s %d', $t, $count));
            }
            $io->success(sprintf('Indexed %d document(s) across %d type(s).', array_sum($totals), count($totals)));
            return Command::SUCCESS;
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::INVALID;
        }
    }
}
