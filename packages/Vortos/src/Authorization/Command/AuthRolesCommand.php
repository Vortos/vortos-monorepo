<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Voter\RoleVoter;

#[AsCommand(name: 'vortos:auth:roles', description: 'Show runtime authorization roles for a user')]
final class AuthRolesCommand extends Command
{
    public function __construct(
        private readonly UserRoleStoreInterface $userRoles,
        private readonly RoleVoter $roleVoter,
        private readonly AuthorizationVersionStoreInterface $versions,
        private readonly EmergencyDenyListInterface $denyList,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = (string) $input->getArgument('user');
        $roles = $this->userRoles->rolesForUser($userId);
        $expanded = $this->roleVoter->expandRoleNames($roles);
        sort($expanded);

        $payload = [
            'user' => $userId,
            'roles' => $roles,
            'expandedRoles' => $expanded,
            'authzVersion' => $this->versions->versionForUser($userId),
            'emergencyDenied' => $this->denyList->isDenied($userId),
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Authorization roles for %s</info>', $userId));
        $output->writeln(sprintf('Authz version: %d', $payload['authzVersion']));
        $output->writeln(sprintf('Emergency denied: %s', $payload['emergencyDenied'] ? 'yes' : 'no'));

        $table = new Table($output);
        $table->setHeaders(['Type', 'Roles']);
        $table->addRow(['DB roles', implode(', ', $roles) ?: '-']);
        $table->addRow(['Expanded roles', implode(', ', $expanded) ?: '-']);
        $table->render();

        return Command::SUCCESS;
    }
}
