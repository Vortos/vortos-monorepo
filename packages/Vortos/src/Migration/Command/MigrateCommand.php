<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProvider;

/**
 * Runs all pending database migrations.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate
 *   php bin/console vortos:migrate --force           # skip confirmation (required in prod deploys)
 *   php bin/console vortos:migrate --dry-run         # preview SQL without applying
 *
 * ## Behaviour
 *
 * Lists pending migrations before executing. In interactive mode asks for confirmation
 * unless --force is supplied. Uses all-or-nothing transaction semantics: if any migration
 * fails the entire run is rolled back.
 *
 * For CI/CD pipelines pass --force --no-interaction to run unattended.
 */
#[AsCommand(
    name: 'vortos:migrate',
    description: 'Run all pending database migrations',
)]
final class MigrateCommand extends Command
{
    public function __construct(private readonly DependencyFactoryProvider $factoryProvider)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview SQL without executing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt (required for production deploys)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $targetVersion = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan          = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($targetVersion);

        if (count($plan) === 0) {
            $output->writeln('<info>Nothing to migrate. Database is up to date.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d pending migration(s):</info>', count($plan)));

        foreach ($plan->getItems() as $item) {
            $output->writeln(sprintf('  <comment>→</comment> %s', (string) $item->getVersion()));
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No changes applied.</comment>');
            return Command::SUCCESS;
        }

        if (!$force && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Proceed? [y/N]</question> ', false))) {
                $output->writeln('<comment>Migration aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $factory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setAllOrNothing(true),
        );

        $output->writeln(sprintf(
            '<info>✔ %d migration(s) executed successfully.</info>',
            count($plan),
        ));

        return Command::SUCCESS;
    }
}
