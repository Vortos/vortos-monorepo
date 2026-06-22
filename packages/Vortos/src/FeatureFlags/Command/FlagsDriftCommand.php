<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\GitOps\DriftEntry;
use Vortos\FeatureFlags\GitOps\DriftType;
use Vortos\FeatureFlags\GitOps\GitOpsDriftService;

#[AsCommand(name: 'vortos:flags:drift', description: 'Detect drift between definition file and runtime state')]
final class FlagsDriftCommand extends Command
{
    public function __construct(
        private readonly GitOpsDriftService $driftService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the definition JSON file')
            ->addOption('check', null, InputOption::VALUE_NONE, 'CI guard: exit 1 if drift detected')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = (string) $input->getArgument('file');

        if (!file_exists($filePath)) {
            $output->writeln(sprintf('<error>File not found: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            $output->writeln(sprintf('<error>Cannot read file: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $output->writeln('<error>Invalid JSON in definition file</error>');
            return Command::FAILURE;
        }

        $report = $this->driftService->detect($data);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $report->hasDrift() && $input->getOption('check') ? Command::FAILURE : Command::SUCCESS;
        }

        if (!$report->hasDrift()) {
            $output->writeln('<info>No drift detected — runtime matches definition file.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<comment>%s</comment>', $report->summary()));
        $output->writeln('');

        foreach ($report->entries as $entry) {
            $this->renderEntry($entry, $output);
        }

        return $input->getOption('check') ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderEntry(DriftEntry $entry, OutputInterface $output): void
    {
        $icon = match ($entry->type) {
            DriftType::FieldMismatch    => '<fg=yellow>~</>',
            DriftType::MissingInRuntime => '<fg=red>-</>',
            DriftType::UndeclaredInFile => '<fg=cyan>?</>',
        };

        $output->writeln(sprintf('  %s %s  <fg=gray>(%s)</>', $icon, $entry->flagName, $entry->details));

        foreach ($entry->fields as $field => $diff) {
            $output->writeln(sprintf(
                '      %s: <fg=red>%s</> → <fg=green>%s</>',
                $field,
                $this->formatValue($diff['effective'] ?? null),
                $this->formatValue($diff['declared'] ?? null),
            ));
        }
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
