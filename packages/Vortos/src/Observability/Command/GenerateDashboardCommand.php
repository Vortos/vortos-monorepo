<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Observability\Dashboard\GrafanaDashboardBuilder;

/**
 * Writes the framework observability dashboard as importable Grafana JSON.
 *
 * Dashboards-as-code, for the same reason infrastructure is: a dashboard that exists only inside
 * Grafana Cloud cannot be reviewed, cannot be diffed, and quietly rots when a metric is renamed —
 * which is discovered mid-incident, staring at an empty graph.
 */
#[AsCommand(
    name: 'vortos:observability:dashboard',
    description: 'Generate the Grafana dashboard JSON for the framework observability metrics',
)]
final class GenerateDashboardCommand extends Command
{
    public function __construct(private readonly GrafanaDashboardBuilder $builder = new GrafanaDashboardBuilder())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write; omit to print to stdout')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Dashboard title', 'Vortos — Platform Observability')
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'Stable dashboard uid (keep it constant so re-imports update in place)', 'vortos-platform')
            ->addOption('datasource', null, InputOption::VALUE_REQUIRED, 'Prometheus datasource uid in Grafana', '${DS_PROMETHEUS}');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $document = $this->builder->build(
            (string) $input->getOption('title'),
            (string) $input->getOption('datasource'),
            (string) $input->getOption('uid'),
        );

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $target = $input->getOption('output');

        if (!is_string($target) || $target === '') {
            $output->write($json);

            return Command::SUCCESS;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            (new SymfonyStyle($input, $output))->error(sprintf('Cannot create directory "%s".', $directory));

            return Command::FAILURE;
        }

        if (file_put_contents($target, $json) === false) {
            (new SymfonyStyle($input, $output))->error(sprintf('Cannot write "%s".', $target));

            return Command::FAILURE;
        }

        (new SymfonyStyle($input, $output))->success(sprintf('Wrote %s (%d panels).', $target, count($document['panels'])));

        return Command::SUCCESS;
    }
}
