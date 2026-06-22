<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\GitOps\FlagDefinitionExporter;

#[AsCommand(name: 'vortos:flags:export', description: 'Export flag definitions to a declarative JSON file')]
final class FlagsExportCommand extends Command
{
    public function __construct(
        private readonly FlagDefinitionExporter $exporter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (default: stdout)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'CI guard: exit 1 if output file differs from current export');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rendered = $this->exporter->render();
        $outPath  = $input->getOption('output');
        $check    = (bool) $input->getOption('check');

        if ($check && is_string($outPath) && $outPath !== '') {
            return $this->runCheck($outPath, $rendered, $output);
        }

        if (is_string($outPath) && $outPath !== '') {
            file_put_contents($outPath, $rendered);
            $data = $this->exporter->export();
            $output->writeln(sprintf(
                '<info>Exported %d flag(s) to %s</info> (version: %s)',
                count($data['flags']),
                $outPath,
                $data['version'],
            ));

            return Command::SUCCESS;
        }

        $output->write($rendered);

        return Command::SUCCESS;
    }

    private function runCheck(string $filePath, string $currentRender, OutputInterface $output): int
    {
        if (!file_exists($filePath)) {
            $output->writeln(sprintf('<error>Definition file not found: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $existing = file_get_contents($filePath);
        if ($existing === false) {
            $output->writeln(sprintf('<error>Cannot read file: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $existingData = json_decode($existing, true);
        $currentData  = json_decode($currentRender, true);

        unset($existingData['exported_at'], $currentData['exported_at']);

        if ($existingData === $currentData) {
            $output->writeln('<info>Definition file is up to date — no drift.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<error>Definition file is out of date. Re-run vortos:flags:export to regenerate.</error>');

        return Command::FAILURE;
    }
}
