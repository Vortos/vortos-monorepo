<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:list', description: 'List all feature flags')]
final class FlagsListCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagScopeContext $scope = new FlagScopeContext(),
        private readonly ProjectContext $projectContext = new ProjectContext(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json',    null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('env',     null, InputOption::VALUE_REQUIRED, 'Filter by environment (default: production)', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Filter by project slug (default: all)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env     = (string) ($input->getOption('env') ?? FlagScopeContext::ENV_PRODUCTION);
        $project = $input->getOption('project');
        $this->scope->withEnvironment($env);

        $flags = $this->storage->findAll();

        if ($project !== null) {
            $project = (string) $project;
            $flags   = array_values(array_filter($flags, fn($f) => $f->projectId === $project));
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn($f) => [
                'name'        => $f->name,
                'enabled'     => $f->enabled,
                'rules'       => count($f->rules),
                'description' => $f->description,
                'project_id'  => $f->projectId,
            ], $flags), JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $projectLabel = $project !== null ? " [project: {$project}]" : '';
        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>Feature Flags</> <fg=gray>(%d)</> <fg=gray>[env: %s%s]</>',
            count($flags),
            $env,
            $projectLabel,
        ));
        $output->writeln('');

        if (empty($flags)) {
            $output->writeln(' <fg=yellow>No flags defined. Run vortos:flags:create to add one.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $style = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($style);
        $table->setHeaders(['<fg=gray>Name</>', '<fg=gray>Status</>', '<fg=gray>Rules</>', '<fg=gray>Project</>', '<fg=gray>Description</>']);

        foreach ($flags as $flag) {
            $status = $flag->enabled
                ? '<fg=green>enabled</>'
                : '<fg=red>disabled</>';

            $rules = count($flag->rules) > 0
                ? sprintf('<fg=cyan>%d rule%s</>', count($flag->rules), count($flag->rules) === 1 ? '' : 's')
                : '<fg=gray>global</>';

            $table->addRow([
                sprintf('<fg=white>%s</>', $flag->name),
                $status,
                $rules,
                sprintf('<fg=gray>%s</>', $flag->projectId),
                sprintf('<fg=gray>%s</>', $flag->description ?: '—'),
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
