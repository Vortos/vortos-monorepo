<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Authorization\Contract\PermissionRegistryInterface;

#[AsCommand(name: 'vortos:auth:permissions', description: 'List registered authorization permissions')]
final class AuthPermissionsCommand extends Command
{
    public function __construct(private readonly PermissionRegistryInterface $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('dangerous', null, InputOption::VALUE_NONE, 'Only show permissions marked dangerous');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $permissions = [];

        foreach ($this->registry->all() as $permission) {
            $metadata = $this->registry->metadata($permission);

            if ($input->getOption('dangerous') && !$metadata?->dangerous) {
                continue;
            }

            $permissions[] = [
                'permission' => $permission,
                'resource' => $metadata?->resource,
                'action' => $metadata?->action,
                'scope' => $metadata?->scope,
                'label' => $metadata?->label,
                'dangerous' => $metadata?->dangerous ?? false,
                'bypassable' => $metadata?->bypassable ?? false,
                'group' => $metadata?->group,
            ];
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($permissions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Authorization permissions (%d)</info>', count($permissions)));

        $table = new Table($output);
        $table->setHeaders(['Permission', 'Group', 'Label', 'Dangerous', 'Bypassable']);

        foreach ($permissions as $permission) {
            $table->addRow([
                $permission['permission'],
                $permission['group'] ?? '',
                $permission['label'] ?? '',
                $permission['dangerous'] ? 'yes' : 'no',
                $permission['bypassable'] ? 'yes' : 'no',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
