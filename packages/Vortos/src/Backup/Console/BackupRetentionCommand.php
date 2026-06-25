<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\RetentionPolicy;
use Vortos\Backup\Port\BackupStoreRegistry;
use Vortos\Backup\Service\RetentionEnforcer;

/**
 * Applies the retention policy. **Dry-run is the default** — deletion is irreversible,
 * so `--apply` is required to actually delete. The plan always prints what it would
 * keep, delete, and *refuse* to delete (floor-protected copies).
 */
#[AsCommand(name: 'backup:retention', description: 'Plan (dry-run) or apply backup retention.')]
final class BackupRetentionCommand extends Command
{
    public function __construct(
        private readonly RetentionEnforcer $enforcer,
        private readonly BackupStoreRegistry $stores,
        private readonly RetentionPolicy $defaultPolicy,
        private readonly string $storeKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'prod')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete (default is a dry-run plan)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');
        $apply = (bool) $input->getOption('apply');

        $store = $this->stores->store($this->storeKey);
        $plan = $this->enforcer->enforce($store, $engine, $env, $this->defaultPolicy, $apply);

        $serialized = $plan->serialize();
        $output->writeln(sprintf('Keep:    %d', count($serialized['keep'])));
        $output->writeln(sprintf('Delete:  %d', count($serialized['delete'])));
        $output->writeln(sprintf('Refused: %d', count($serialized['refused'])));

        foreach ($serialized['delete'] as $id) {
            $output->writeln(sprintf('  <comment>delete</comment> %s', $id));
        }
        foreach ($serialized['refused'] as $r) {
            $output->writeln(sprintf('  <info>refused</info> %s (%s)', $r['key'], $r['reason']));
        }

        if (!$apply) {
            $output->writeln('<comment>Dry-run — nothing was deleted. Re-run with --apply to delete.</comment>');
        }

        return self::SUCCESS;
    }
}
