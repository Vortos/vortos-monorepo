<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Authorization\Engine\PolicyEngine;

#[AsCommand(name: 'vortos:auth:can', description: 'Check whether a user can perform a permission')]
final class AuthCanCommand extends Command
{
    public function __construct(
        private readonly AuthCommandIdentityFactory $identityFactory,
        private readonly PolicyEngine $engine,
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
        $decision = $this->engine->decide($identity, $permission);
        $payload = [
            'user' => $userId,
            'permission' => $permission,
            'allowed' => $decision->allowed(),
            'reason' => $decision->reason(),
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

        return $decision->allowed() ? Command::SUCCESS : Command::FAILURE;
    }
}
