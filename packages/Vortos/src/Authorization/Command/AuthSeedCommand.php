<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Authorization\Contract\PermissionRegistryInterface;

#[AsCommand(
    name: 'vortos:auth:seed',
    description: 'Seed catalog default authorization grants into the runtime store',
)]
final class AuthSeedCommand extends Command
{
    public function __construct(
        private readonly PermissionRegistryInterface $registry,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show seed counts without writing to the database',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $rows = $this->seedRows();

        if ($rows === []) {
            $output->writeln('<comment>No catalog default grants found.</comment>');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '<info>Would seed %d role permission grant(s) across %d role(s).</info>',
                count($rows),
                count($this->registry->defaultGrants()),
            ));

            return Command::SUCCESS;
        }

        $inserted = $this->bulkInsert($rows);

        $output->writeln(sprintf(
            '<info>Seeded %d new role permission grant(s).</info>',
            $inserted,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{role: string, permission: string}>
     */
    private function seedRows(): array
    {
        $rows = [];

        foreach ($this->registry->defaultGrants() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $rows[] = ['role' => $role, 'permission' => $permission];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{role: string, permission: string}> $rows
     */
    private function bulkInsert(array $rows): int
    {
        $placeholders = [];
        $params = [];

        foreach ($rows as $i => $row) {
            $roleParam = 'role' . $i;
            $permissionParam = 'permission' . $i;
            $placeholders[] = sprintf('(:%s, :%s)', $roleParam, $permissionParam);
            $params[$roleParam] = $row['role'];
            $params[$permissionParam] = $row['permission'];
        }

        return $this->connection->executeStatement(sprintf(
            'INSERT INTO role_permissions (role, permission) VALUES %s ON CONFLICT (role, permission) DO NOTHING',
            implode(', ', $placeholders),
        ), $params);
    }
}
