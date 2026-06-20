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
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Decision\AuthorizationDecisionReason;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Identity\RequestAuthzVersionProvider;
use Vortos\Authorization\Scope\ScopeEnforcementClassifier;

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
        private readonly RequestAuthzVersionProvider $authzVersionProvider,
        private readonly ScopeEnforcementClassifier $scopeClassifier,
        private readonly PolicyRegistryInterface $policies,
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

        $parts = explode('.', $permission);
        $resourceType = $parts[0];
        $scope = $parts[2] ?? '';
        $scopeKind = $scope === '' ? null : $this->scopeClassifier->classify($scope)->value;
        $policyRegistered = $resourceType !== '' && $this->policies->hasForResource($resourceType);

        $payload = [
            'user' => $userId,
            'permission' => $permission,
            'decision' => [
                'allowed' => $decision->allowed(),
                'reason' => $decision->reason(),
                'path' => $this->describePath($decision->reason()),
            ],
            'scope' => $scope,
            'scopeEnforcement' => $scopeKind,
            'policyRegistered' => $policyRegistered,
            'tokenRoles' => $identity->roles(),
            'dbRoles' => $this->userRoles->rolesForUser($userId),
            'expandedRoles' => $resolved->expandedRoles(),
            'authzVersion' => [
                'claim' => $this->authzVersionProvider->get() ?? 0,
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
            '%s %s because %s (%s)',
            $decision->allowed() ? '<info>ALLOWED</info>' : '<error>DENIED</error>',
            $permission,
            $decision->reason(),
            $payload['decision']['path'],
        ));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['Field', 'Value']);
        $table->addRows([
            ['User', $userId],
            ['Scope', $scope ?: '-'],
            ['Scope enforcement', $scopeKind ?? '-'],
            ['Policy registered', $policyRegistered ? 'yes' : 'no'],
            ['Decision path', $payload['decision']['path']],
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

    /**
     * Human-readable description of which layer decided, derived from the reason.
     */
    private function describePath(string $reason): string
    {
        return match ($reason) {
            AuthorizationDecisionReason::Allowed->value => 'resource policy allowed',
            AuthorizationDecisionReason::RbacAuthoritative->value => 'no policy — RBAC authoritative (self-sufficient scope)',
            AuthorizationDecisionReason::ScopeSatisfied->value => 'no policy — scoped store satisfied the containment binding',
            AuthorizationDecisionReason::ExternallyEnforced->value => 'no policy — relationship enforced elsewhere (declared)',
            AuthorizationDecisionReason::ResourceDenied->value => 'resource policy denied',
            AuthorizationDecisionReason::PolicyOrScopeRequired->value => 'no policy and scope unenforced — fail closed',
            AuthorizationDecisionReason::PolicyRequired->value => 'permission requires a policy — fail closed',
            AuthorizationDecisionReason::ScopedPermissionDenied->value => 'scoped store denied the binding',
            AuthorizationDecisionReason::MissingPermission->value => 'RBAC does not grant this permission',
            AuthorizationDecisionReason::UnknownPermission->value => 'permission is not registered',
            AuthorizationDecisionReason::Unauthenticated->value => 'identity is not authenticated',
            AuthorizationDecisionReason::EmergencyDenied->value => 'identity is on the emergency deny list',
            AuthorizationDecisionReason::StaleToken->value => 'authz version claim is stale',
            AuthorizationDecisionReason::InvalidPermissionFormat->value => 'permission string is malformed',
            default => $reason,
        };
    }
}
