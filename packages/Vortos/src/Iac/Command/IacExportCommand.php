<?php

declare(strict_types=1);

namespace Vortos\Iac\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Export\ExportRunner;
use Vortos\Iac\Export\FileOutcome;

/**
 * Deliberately thin: WHAT gets exported and HOW lives entirely in
 * InfraConfig classes, compiled at container build time. The command only
 * chooses between writing, checking, and printing.
 *
 * Exit codes: 0 success · 1 drift (--check) · 2 failure.
 */
#[AsCommand(
    name: 'vortos:iac:export',
    description: 'Generate Terraform (.tf.json) files from InfraConfig exporters. --check for CI drift detection.',
)]
final class IacExportCommand extends Command
{
    public function __construct(private readonly ExportRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Verify files on disk match the generated output; exit 1 on drift. Run in CI.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print generated files to stdout without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('check') && $input->getOption('dry-run')) {
            $io->error('--check and --dry-run are mutually exclusive.');
            return 2;
        }

        $mode = $input->getOption('check') ? 'check' : ($input->getOption('dry-run') ? 'dry-run' : 'write');

        try {
            $results = $this->runner->run($mode);
        } catch (IacException $e) {
            $io->error($e->getMessage());
            return 2;
        }

        if ($results === []) {
            $io->warning('No exporters registered. Add an #[InfraConfig] class with #[RegisterTerraformExporter] methods.');
            return Command::SUCCESS;
        }

        if ($mode === 'dry-run') {
            foreach ($results as $result) {
                $io->section($result['file']);
                $output->write($result['content']);
            }
            return Command::SUCCESS;
        }

        $io->table(
            ['Exporter', 'Resources', 'File', 'Status'],
            array_map(
                static fn(array $r) => [$r['name'], $r['resources'], $r['file'], $r['outcome']->value],
                $results,
            ),
        );

        foreach ($results as $result) {
            if ($result['resources'] === 0) {
                $io->warning(sprintf(
                    "Exporter '%s' matched zero resources — check its only()/exclude() filters.",
                    $result['name'],
                ));
            }
        }

        $drifted = array_values(array_filter($results, static fn(array $r) => $r['outcome'] === FileOutcome::Drift));

        if ($drifted !== []) {
            $io->error(sprintf(
                "%d file(s) drifted from the generated output: %s\nRun vortos:iac:export to regenerate.",
                count($drifted),
                implode(', ', array_column($drifted, 'file')),
            ));
            return Command::FAILURE;
        }

        $io->success($mode === 'check' ? 'All generated files match.' : 'Export complete.');

        return Command::SUCCESS;
    }
}
