<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:enable', description: 'Enable a feature flag (optionally with a rollout percentage)')]
final class FlagsEnableCommand extends Command
{
    public function __construct(private readonly FlagStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('rollout', null, InputOption::VALUE_REQUIRED, 'Percentage rollout 1–100 (omit for full rollout)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $rollout = $input->getOption('rollout');
        $rules   = $flag->rules;

        if ($rollout !== null) {
            $pct = (int) $rollout;

            if ($pct < 1 || $pct > 100) {
                $output->writeln('<error>--rollout must be between 1 and 100.</error>');
                return Command::FAILURE;
            }

            // Replace any existing percentage rule, keep others
            $rules = array_values(array_filter($rules, fn($r) => $r->type !== FlagRule::TYPE_PERCENTAGE));
            if ($pct < 100) {
                $rules[] = new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: $pct);
            }
        }

        $this->storage->save($flag->withEnabled(true)->withRules($rules));

        $suffix = $rollout !== null && (int) $rollout < 100
            ? sprintf(' <fg=cyan>(%d%% rollout)</>', (int) $rollout)
            : ' <fg=gray>(100% — all users)</>';

        $output->writeln(sprintf('  <info>enabled:</info> %s%s', $name, $suffix));

        return Command::SUCCESS;
    }
}
