<?php

declare(strict_types=1);

namespace Vortos\Authorization\Command;

use Doctrine\DBAL\ArrayParameterType;
use Vortos\Authorization\Resolver\RoleGenerationStore;
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
    /**
     * @param RoleGenerationStore|null $generations bumps the per-role cache generation after a seed
     *        that changed anything. Optional because a deployment without Redis has no generation
     *        store and no cache to invalidate.
     */
    public function __construct(
        private readonly PermissionRegistryInterface $registry,
        private readonly Connection $connection,
        private readonly string $rolePermissionsTable,
        private readonly ?RoleGenerationStore $generations = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show seed/prune counts without writing to the database',
            )
            ->addOption(
                'prune',
                null,
                InputOption::VALUE_NONE,
                'Also delete grants whose permission no longer exists in any catalog (dead permissions). '
                    . 'Never removes a grant for a permission that still exists but is no longer a role default — '
                    . 'those may be intentional runtime grants and require manual review.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $prune = (bool) $input->getOption('prune');

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

            if ($prune) {
                $output->writeln(sprintf(
                    '<info>Would prune %d grant(s) referencing permissions absent from every catalog.</info>',
                    $this->prunableCount($output),
                ));
            }

            return Command::SUCCESS;
        }

        $inserted = $this->bulkInsert($rows);

        $output->writeln(sprintf(
            '<info>Seeded %d new role permission grant(s).</info>',
            $inserted,
        ));

        $pruned = 0;

        if ($prune) {
            $pruned = $this->prune($output);

            $output->writeln(sprintf(
                '<info>Pruned %d dead role permission grant(s).</info>',
                $pruned,
            ));
        }

        $this->invalidateResolvedPermissions($inserted + $pruned, $output);

        return Command::SUCCESS;
    }

    /**
     * Bust the resolved-permission cache for every seeded role.
     *
     * This command writes grants straight to the table with DBAL, bypassing
     * {@see \Vortos\Authorization\Storage\GenerationalRolePermissionStore::grant()} — the only
     * path that increments a role's generation. So the database was correct while every running
     * process kept resolving the PREVIOUS permission matrix: a freshly granted permission returned
     * 403 until the cache entry expired on its own.
     *
     * That is not theoretical. `deploy.yml` runs `vortos:auth:seed --prune` before cutover, and
     * Redis outlives the app containers, so any newly added permission was invisible after every
     * deploy that introduced one.
     *
     * Incrementing a generation can only ever invalidate a cache entry — it never grants access —
     * so bumping every seeded role is the safe direction. Roles are taken from the catalog rather
     * than from the rows written, because a prune changes what a role resolves to without
     * necessarily inserting anything for it.
     */
    private function invalidateResolvedPermissions(int $changes, OutputInterface $output): void
    {
        if ($changes === 0) {
            return; // nothing moved; every cached matrix is still accurate
        }

        if ($this->generations === null) {
            $output->writeln(
                '<comment>No role generation store configured — resolved permissions are not cached, '
                . 'so nothing to invalidate.</comment>',
            );

            return;
        }

        $roles = array_keys($this->registry->defaultGrants());

        foreach ($roles as $role) {
            $this->generations->increment((string) $role);
        }

        $output->writeln(sprintf(
            '<info>Invalidated the cached permission matrix for %d role(s).</info>',
            count($roles),
        ));
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

        return (int) $this->connection->executeStatement(sprintf(
            'INSERT INTO %s (role, permission) VALUES %s ON CONFLICT (role, permission) DO NOTHING',
            $this->rolePermissionsTable,
            implode(', ', $placeholders),
        ), $params);
    }

    /**
     * Grants whose permission is absent from the entire live registry are dead — nothing in
     * code can ever check them, so removing them is safe. This deliberately never touches a
     * grant whose permission still exists (only its default status may have changed); those
     * stay for manual review.
     */
    private function prune(OutputInterface $output): int
    {
        $live = $this->livePermissions();

        if ($live === []) {
            $output->writeln(
                '<comment>Registry reports zero live permissions; skipping prune to avoid deleting every grant.</comment>',
            );

            return 0;
        }

        return (int) $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE permission NOT IN (:live)', $this->rolePermissionsTable),
            ['live' => $live],
            ['live' => ArrayParameterType::STRING],
        );
    }

    private function prunableCount(OutputInterface $output): int
    {
        $live = $this->livePermissions();

        if ($live === []) {
            $output->writeln(
                '<comment>Registry reports zero live permissions; prune would be skipped to avoid deleting every grant.</comment>',
            );

            return 0;
        }

        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE permission NOT IN (:live)', $this->rolePermissionsTable),
            ['live' => $live],
            ['live' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return string[]
     */
    private function livePermissions(): array
    {
        return array_values($this->registry->all());
    }
}
