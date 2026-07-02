<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Pitr\ContainerizedPitrRecipe;

/**
 * Emits the containerized point-in-time-recovery (WAL shipping) recipe so a PHP-less Postgres
 * image can archive WAL to a shared volume and a shipper worker moves it off-host (upstream P3-1).
 */
#[AsCommand(
    name: 'vortos:backup:pitr:recipe',
    description: 'Generate a containerized WAL-shipping PITR recipe (postgresql.conf + shipper/base-backup workers + compose fragment).',
)]
final class BackupPitrRecipeCommand extends Command
{
    public function __construct(
        private readonly ContainerizedPitrRecipe $recipe,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('wal-volume', null, InputOption::VALUE_REQUIRED, 'Shared WAL volume mount path', '/wal_archive')
            ->addOption('backend-service', null, InputOption::VALUE_REQUIRED, 'App/backend compose service (owns the workers)', 'backend')
            ->addOption('postgres-service', null, InputOption::VALUE_REQUIRED, 'Postgres compose service', 'postgres')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment name', 'prod')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print artifacts without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $artifacts = $this->recipe->generate(
            walVolume: (string) $input->getOption('wal-volume'),
            backendService: (string) $input->getOption('backend-service'),
            postgresService: (string) $input->getOption('postgres-service'),
            environment: (string) $input->getOption('env'),
        );

        foreach ($artifacts as $relativePath => $contents) {
            if ($dryRun) {
                $output->writeln(sprintf('<info># %s</info>', $relativePath));
                $output->writeln($contents);
                continue;
            }

            $target = rtrim($this->projectDir, '/') . '/' . $relativePath;
            if (is_file($target) && !$force) {
                $output->writeln(sprintf('<comment>[SKIPPED]</comment> %s (exists; use --force)', $relativePath));
                continue;
            }

            $dir = \dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($target, $contents);
            $output->writeln(sprintf('<info>[WRITTEN]</info> %s', $relativePath));
        }

        return Command::SUCCESS;
    }
}
