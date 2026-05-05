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
use Vortos\Authorization\Admin\UserRoleAdminService;

#[AsCommand(name: 'vortos:auth:user-role:assign', description: 'Assign a runtime role to a user')]
final class AuthAssignUserRoleCommand extends Command
{
    public function __construct(private readonly UserRoleAdminService $admin)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::REQUIRED, 'Target user ID')
            ->addArgument('role', InputArgument::REQUIRED, 'Role name')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Admin user ID performing the change')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Audit reason')
            ->addOption('metadata', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Audit metadata as key=value')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = (string) $input->getArgument('user');
        $role = (string) $input->getArgument('role');

        try {
            $actor = $this->requiredOption($input, 'actor');
            $metadata = $this->metadata($input);
            $this->admin->assign($actor, $user, $role, $this->nullableOption($input, 'reason'), array_merge($metadata, ['source' => 'console']));
        } catch (InvalidArgumentException $e) {
            $this->writeError($input, $output, $e->getMessage());
            return Command::INVALID;
        }

        return $this->writeSuccess($input, $output, [
            'action' => 'user_role.assigned',
            'actor' => $actor,
            'user' => $user,
            'role' => $role,
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
            '<info>Assigned</info> %s to %s',
            $payload['role'],
            $payload['user'],
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
