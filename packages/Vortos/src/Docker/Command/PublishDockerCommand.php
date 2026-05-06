<?php
declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Service\DockerFilePublisher;

#[AsCommand(
    name: 'vortos:docker:publish',
    description: 'Publish Docker files to your project'
)]
final class PublishDockerCommand extends Command
{
    public function __construct(private readonly DockerFilePublisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'runtime',
            'r',
            InputOption::VALUE_OPTIONAL,
            'Runtime to use: frankenphp or phpfpm',
            'frankenphp'
        )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files without writing them')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Overwrite changed files without creating .bak copies')
            ->addOption('no-overwrite', null, InputOption::VALUE_NONE, 'Skip files that already exist with different content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runtime = (string) $input->getOption('runtime');
        $projectRoot = getcwd();

        try {
            $result = $this->publisher->publish(
                $runtime,
                $projectRoot,
                (bool) $input->getOption('dry-run'),
                !(bool) $input->getOption('no-backup'),
                !(bool) $input->getOption('no-overwrite'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s Docker files %s for %s runtime.',
            count($result->copied),
            $input->getOption('dry-run') ? 'would be published' : 'published',
            $runtime,
        ));

        if ($result->backedUp !== []) {
            $io->section('Backups');
            $io->listing($result->backedUp);
        }

        if ($result->skipped !== []) {
            $io->section('Skipped unchanged or protected files');
            $io->listing($result->skipped);
        }

        return Command::SUCCESS;
    }
}
