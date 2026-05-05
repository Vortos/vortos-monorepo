<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Authorization\Admin\RolePermissionAdminService;

#[AsCommand(name: 'vortos:auth:role-permission:revoke', description: 'Revoke a registered permission from a runtime role')]
final class AuthRevokeRolePermissionCommand extends Command
{
    public function __construct(private readonly RolePermissionAdminService $admin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('role', InputArgument::REQUIRED, 'Role name')
            ->addArgument('permission', InputArgument::REQUIRED, 'Registered permission string')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Admin user ID performing the change')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Audit reason')
            ->addOption('metadata', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Audit metadata as key=value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $role = (string) $input->getArgument('role');
        $permission = (string) $input->getArgument('permission');

        try {
            $actor = $this->requiredOption($input, 'actor');
            $reason = $this->nullableOption($input, 'reason');
            $metadata = $this->metadata($input);
            $this->admin->revoke($actor, $role, $permission, $reason, array_merge($metadata, ['source' => 'console']));
        } catch (InvalidArgumentException $e) {
            $this->writeError($input, $output, $e->getMessage());
            return Command::INVALID;
        }

        return $this->writeSuccess($input, $output, [
            'action' => 'role_permission.revoked',
            'actor' => $actor,
            'role' => $role,
            'permission' => $permission,
        ]);
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = trim((string) $input->getOption($name));

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    private function nullableOption(InputInterface $input, string $name): ?string
    {
        $value = trim((string) $input->getOption($name));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string>
     */
    private function metadata(InputInterface $input): array
    {
        $metadata = [];

        foreach ((array) $input->getOption('metadata') as $pair) {
            $parts = explode('=', (string) $pair, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '') {
                throw new InvalidArgumentException('Metadata must be passed as key=value.');
            }

            $metadata[trim($parts[0])] = $parts[1];
        }

        return $metadata;
    }

    /**
     * @param array<string, string> $payload
     */
    private function writeSuccess(InputInterface $input, OutputInterface $output, array $payload): int
    {
        if ($input->getOption('json')) {
            $output->writeln(json_encode($payload + ['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Revoked</info> %s from %s',
            $payload['permission'],
            $payload['role'],
        ));

        return Command::SUCCESS;
    }

    private function writeError(InputInterface $input, OutputInterface $output, string $message): void
    {
        if ($input->getOption('json')) {
            $output->writeln(json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }

        $output->writeln(sprintf('<error>%s</error>', $message));
    }
}
