<?php

declare(strict_types=1);

namespace Vortos\Foundation\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Foundation\Assets\AssetPublisher;

#[AsCommand(
    name: 'vortos:assets:publish',
    description: 'Copy (or symlink) public assets from installed Vortos packages into the application public directory',
)]
final class AssetsPublishCommand extends Command
{
    public function __construct(
        private readonly AssetPublisher $publisher,
        private readonly string $vendorDir,
        private readonly string $defaultPublicDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'symlink',
                null,
                InputOption::VALUE_NONE,
                'Symlink assets instead of copying (recommended for development)',
            )
            ->addOption(
                'public-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Path to the application public directory',
                $this->defaultPublicDir,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symlink   = (bool) $input->getOption('symlink');
        $publicDir = (string) $input->getOption('public-dir');

        $output->writeln('<info>VORTOS ASSETS:PUBLISH</info>');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln('');

        $results = $this->publisher->publish($this->vendorDir, $publicDir, $symlink);

        if ($results === []) {
            $output->writeln('  <comment>No packages with publishable assets found.</comment>');
            $output->writeln('  <fg=gray>Packages opt in via extra.vortos.public-dir in their composer.json.</>');
            $output->writeln('');

            return Command::SUCCESS;
        }

        $failed = 0;

        foreach ($results as $result) {
            if ($result->action === 'failed') {
                $failed++;
                $badge = '<error>[FAIL]  </>';
            } else {
                $badge = '<info>[OK]    </>';
            }

            $output->writeln(sprintf(
                '  %s  %-44s  → %s',
                $badge,
                $result->package,
                $result->target,
            ));

            if ($result->error !== null) {
                $output->writeln(sprintf('         <fg=gray>Error: %s</>', $result->error));
            }
        }

        $output->writeln('');
        $output->writeln('<fg=gray>' . str_repeat('─', 60) . '</>');
        $output->writeln(sprintf(
            '  <info>%d published</>  %s  <fg=gray>(%s)</>',
            count($results) - $failed,
            $failed > 0 ? "<error>{$failed} failed</>" : '<info>0 failed</>',
            $symlink ? 'symlinked' : 'copied',
        ));
        $output->writeln('');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
