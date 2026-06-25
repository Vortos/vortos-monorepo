<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Schedule\BackupScheduleRegistry;
use Vortos\Backup\Schedule\CronFragmentGenerator;

/**
 * Emits (or writes) the managed cron fragment for the declared backup schedules.
 * The framework does not run a scheduler; this generates the host-side trigger.
 */
#[AsCommand(name: 'backup:schedule', description: 'Generate the host cron fragment for declared backup schedules.')]
final class BackupScheduleCommand extends Command
{
    public function __construct(
        private readonly BackupScheduleRegistry $schedules,
        private readonly CronFragmentGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('write', null, InputOption::VALUE_REQUIRED, 'Write the fragment to this path instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fragment = $this->generator->generate($this->schedules->all());

        $path = $input->getOption('write');
        if (is_string($path) && $path !== '') {
            if (file_put_contents($path, $fragment) === false) {
                $output->writeln(sprintf('<error>Could not write fragment to %s</error>', $path));

                return self::FAILURE;
            }
            $output->writeln(sprintf('<info>Wrote cron fragment to %s</info>', $path));

            return self::SUCCESS;
        }

        $output->write($fragment);

        return self::SUCCESS;
    }
}
