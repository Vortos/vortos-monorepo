<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Observability\Service\ObservabilityTemplatePublisher;

#[AsCommand(
    name: 'vortos:observability:publish',
    description: 'Publish optional observability templates for Prometheus, Grafana, Alertmanager, Datadog, or New Relic',
)]
final class PublishObservabilityTemplatesCommand extends Command
{
    public function __construct(private readonly ObservabilityTemplatePublisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('stack', 's', InputOption::VALUE_REQUIRED, 'Stack to publish, e.g. prometheus, grafana, alertmanager, datadog, newrelic, grafana-oss', 'grafana-oss')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing template files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files without writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stack = (string) $input->getOption('stack');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry run - no files will be written.');
        }

        try {
            $result = $this->publisher->publish((string) getcwd(), $stack, $force, $dryRun);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($result->published !== []) {
            $io->success(sprintf(
                '%s observability template(s) %s:',
                count($result->published),
                $dryRun ? 'would be published' : 'published',
            ));
            $io->listing($result->published);
        }

        if ($result->skipped !== []) {
            $io->section('Skipped (already exists - use --force to overwrite)');
            $io->listing($result->skipped);
        }

        if ($result->published === [] && $result->skipped === []) {
            $io->info('Nothing to publish.');
        }

        if (!$dryRun && $result->published !== []) {
            $io->note('Templates are starter assets. Review thresholds, labels, routes, and notification policies before production use.');
        }

        return Command::SUCCESS;
    }
}

