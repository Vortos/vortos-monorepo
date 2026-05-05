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
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Engine\PolicyEngine;

#[AsCommand(name: 'vortos:auth:explain', description: 'Explain an authorization decision for a user')]
final class AuthExplainCommand extends Command
{
    public function __construct(
        private readonly AuthCommandIdentityFactory $identityFactory,
        private readonly PolicyEngine $engine,
        private readonly PermissionResolverInterface $resolver,
        private readonly UserRoleStoreInterface $userRoles,
        private readonly RolePermissionStoreInterface $rolePermissions,
        private readonly AuthorizationVersionStoreInterface $versions,
        private readonly EmergencyDenyListInterface $denyList,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'User ID')
            ->addArgument('permission', InputArgument::REQUIRED, 'Permission string')
            ->addOption('role', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'JWT/bootstrap role to include')
            ->addOption('authz-version', null, InputOption::VALUE_REQUIRED, 'Authz version claim to simulate')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userId = (string) $input->getArgument('user');
        $permission = (string) $input->getArgument('permission');
        $authzVersion = $input->getOption('authz-version');
        $identity = $this->identityFactory->create(
            $userId,
            $input->getOption('role'),
            $authzVersion === null ? null : (int) $authzVersion,
        );
        $resolved = $this->resolver->resolve($identity);
        $decision = $this->engine->decide($identity, $permission);
        $roleGrants = $this->rolePermissions->permissionsForRoles($resolved->expandedRoles());

        $payload = [
            'user' => $userId,
            'permission' => $permission,
            'decision' => [
                'allowed' => $decision->allowed(),
                'reason' => $decision->reason(),
            ],
            'tokenRoles' => $identity->roles(),
            'dbRoles' => $this->userRoles->rolesForUser($userId),
            'expandedRoles' => $resolved->expandedRoles(),
            'authzVersion' => [
                'claim' => $identity->getAttribute('authz_version', 0),
                'runtime' => $this->versions->versionForUser($userId),
            ],
            'emergencyDenied' => $this->denyList->isDenied($userId),
            'permissionCount' => $resolved->count(),
            'hasPermission' => $resolved->has($permission),
            'roleGrants' => $roleGrants,
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $decision->allowed() ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln(sprintf(
            '%s %s because %s',
            $decision->allowed() ? '<info>ALLOWED</info>' : '<error>DENIED</error>',
            $permission,
            $decision->reason(),
        ));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Field', 'Value']);
        $table->addRows([
            ['User', $userId],
            ['Token roles', implode(', ', $payload['tokenRoles']) ?: '-'],
            ['DB roles', implode(', ', $payload['dbRoles']) ?: '-'],
            ['Expanded roles', implode(', ', $payload['expandedRoles']) ?: '-'],
            ['Authz version claim', (string) $payload['authzVersion']['claim']],
            ['Authz version runtime', (string) $payload['authzVersion']['runtime']],
            ['Emergency denied', $payload['emergencyDenied'] ? 'yes' : 'no'],
            ['Resolved permission count', (string) $payload['permissionCount']],
            ['Requested permission present', $payload['hasPermission'] ? 'yes' : 'no'],
        ]);
        $table->render();

        return $decision->allowed() ? Command::SUCCESS : Command::FAILURE;
    }
}
