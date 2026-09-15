<?php

declare(strict_types=1);

namespace Vortos\Migration\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Access\DatabaseRoleConvergenceSql;
use Vortos\Migration\Access\ModuleTableResolverInterface;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * Applies the owner tier of the role model — every grant and default privilege on objects the owner owns — in one
 * transaction over this process's connection.
 *
 * Meant for the deploy's pre-cutover step, after migrations: a table a migration just created is granted to the
 * runtime role before the colour that queries it takes traffic, and a module table the backup node writes is
 * granted before it first writes. Refuses to run as anyone but the declared owner, so a mis-delivered credential
 * fails loudly here instead of granting as the wrong role.
 *
 * Output is written, not logged: production hides info-level logs. Exit code: 0 = applied, 2 = refused.
 */
#[AsCommand(name: 'vortos:database:roles:grant', description: 'Apply the owner-tier grants of the declared least-privilege role model.')]
final class DatabaseRolesGrantCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DatabaseRoleConvergenceSql $sql,
        private readonly ModuleTableResolverInterface $tables,
        private readonly ?DeclaredDatabaseRoles $declared,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $model = $this->declared?->model();
        if ($model === null) {
            $output->writeln('No database role model is declared in config/persistence.php; nothing to grant.');

            return 2;
        }

        $current = (string) $this->connection->fetchOne('SELECT current_user');
        if ($current !== $model->owner->value) {
            $output->writeln(sprintf('Refusing: connected as "%s", the owner-tier grants must be applied by the owner role "%s".', $current, $model->owner->value));

            return 2;
        }

        $statements = $this->sql->ownerStatements($model, $model->backup === null ? [] : $this->tables->tablesOf($model->backupWritableModules));
        $this->connection->transactional(function (Connection $connection) use ($statements): void {
            foreach ($statements as $statement) {
                $connection->executeStatement($statement);
            }
        });

        $output->writeln(sprintf('Owner-tier grants applied: %d statements in one transaction as %s.', \count($statements), $current));

        return Command::SUCCESS;
    }
}
