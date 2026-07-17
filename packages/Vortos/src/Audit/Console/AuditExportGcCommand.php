<?php

declare(strict_types=1);

namespace Vortos\Audit\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Audit\Export\AuditExportGarbageCollector;

/**
 * Garbage-collects expired audit export artifacts (delete object + mark job Expired). Meant to
 * be scheduled (e.g. hourly). No-op when async export is not wired (no object-store target).
 *
 *   php bin/console vortos:audit:export:gc --limit=200
 */
#[AsCommand(name: 'vortos:audit:export:gc', description: 'Delete expired audit export artifacts and mark their jobs expired.')]
final class AuditExportGcCommand extends Command
{
    public function __construct(private readonly ?AuditExportGarbageCollector $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum artifacts to collect this run.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->collector === null) {
            $io->warning('Async export is not configured (no object-store target). Nothing to collect.');
            return Command::SUCCESS;
        }

        $limit     = max(1, (int) $input->getOption('limit'));
        $collected = $this->collector->collect($limit);

        $io->success(sprintf('Collected %s expired audit export artifact(s).', number_format($collected)));

        return Command::SUCCESS;
    }
}
