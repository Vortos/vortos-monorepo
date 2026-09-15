<?php

declare(strict_types=1);

namespace Vortos\Migration\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Access\ConvergenceAuthority;
use Vortos\Migration\Access\DatabaseCredentialFile;
use Vortos\Migration\Access\DatabaseRoleConvergenceSql;
use Vortos\Migration\Access\DatabaseRoleKind;
use Vortos\Migration\Access\ModuleTableResolverInterface;
use Vortos\Migration\Access\ScramSha256Verifier;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * Prints the SQL that converges the database to the declared role model, for an operator to pipe into psql.
 *
 * The superuser tier needs a superuser, which no application or deploy credential is — by design. So this command
 * never connects: it renders, and the operator runs the output through the server's local socket
 * (`… | docker exec -i <db> psql -X -v ON_ERROR_STOP=1 -U <bootstrap> -d <database>`).
 *
 * Passwords: every LOGIN role's credential file must be named (`--credentials=runtime:/dev/shm/…/.env.prod`), or
 * `--keep-passwords` must say that no password changes. There is no middle: a model half-applied with some roles
 * unable to log in is an outage. Only SCRAM verifiers are printed.
 *
 * Exit code: 0 = rendered, 2 = refused (nothing printed).
 */
#[AsCommand(name: 'vortos:database:roles:sql', description: 'Print the SQL that converges the database to the declared least-privilege role model.')]
final class DatabaseRolesSqlCommand extends Command
{
    public function __construct(
        private readonly DatabaseRoleConvergenceSql $sql,
        private readonly ModuleTableResolverInterface $tables,
        private readonly ?DeclaredDatabaseRoles $declared,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('authority', null, InputOption::VALUE_REQUIRED, 'superuser | owner');
        $this->addOption('credentials', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '<owner|runtime|backup>:<env file path> — the file whose VORTOS_WRITE_DB_DSN that role logs in with');
        $this->addOption('keep-passwords', null, InputOption::VALUE_NONE, 'Change no password (superuser tier)');
        $this->addOption('clear-bootstrap-password', null, InputOption::VALUE_NONE, 'Remove the bootstrap superuser password so it can log in only over the local socket (superuser tier)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $error = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $model = $this->declared?->model();
        if ($model === null) {
            $error->writeln('No database role model is declared in config/persistence.php.');

            return 2;
        }

        $authority = ConvergenceAuthority::tryFrom((string) $input->getOption('authority'));
        if ($authority === null) {
            $error->writeln('--authority must be "superuser" or "owner".');

            return 2;
        }

        try {
            if ($authority === ConvergenceAuthority::Owner) {
                if ($input->getOption('credentials') !== [] || $input->getOption('keep-passwords') || $input->getOption('clear-bootstrap-password')) {
                    $error->writeln('The owner tier sets no passwords; --credentials, --keep-passwords and --clear-bootstrap-password belong to the superuser tier.');

                    return 2;
                }
                $statements = $this->sql->ownerStatements($model, $model->backup === null ? [] : $this->tables->tablesOf($model->backupWritableModules));
                $output->writeln('-- Vortos database role convergence: OWNER tier. One transaction; run as the owner role or the bootstrap superuser.');
                $output->writeln('BEGIN;');
                foreach ($statements as $statement) {
                    $output->writeln($statement . ';');
                }
                $output->writeln('COMMIT;');

                return Command::SUCCESS;
            }

            $verifiers = $this->verifiers($model, $input);
            if ($verifiers === null) {
                $error->writeln('Name the credential file of every login role (--credentials=owner:…, runtime:…, backup:…), or pass --keep-passwords.');

                return 2;
            }
            $statements = $this->sql->superuserStatements($model, $verifiers, (bool) $input->getOption('clear-bootstrap-password'));
            $output->writeln(sprintf(
                '-- Vortos database role convergence: SUPERUSER tier. Run as the bootstrap superuser, connected to database %s, in autocommit mode: psql -X -v ON_ERROR_STOP=1.',
                $model->database,
            ));
            foreach ($statements as $statement) {
                $output->writeln($statement . ';');
            }
        } catch (\InvalidArgumentException $e) {
            $error->writeln($e->getMessage());

            return 2;
        }

        return Command::SUCCESS;
    }

    /** @return array<string, string>|null role name => verifier; null when the password choice is incomplete */
    private function verifiers(\Vortos\Persistence\Access\DatabaseRoleModel $model, InputInterface $input): ?array
    {
        /** @var list<string> $given */
        $given = $input->getOption('credentials');
        if ($input->getOption('keep-passwords')) {
            return $given === [] ? [] : null;
        }

        $files = [];
        foreach ($given as $entry) {
            [$kindName, $path] = array_pad(explode(':', $entry, 2), 2, '');
            $kind = DatabaseRoleKind::tryFrom($kindName);
            if ($kind === null || $kind === DatabaseRoleKind::Bootstrap || $path === '' || isset($files[$kind->value])) {
                throw new \InvalidArgumentException(sprintf('Invalid --credentials entry for "%s": use owner|runtime|backup:<path>, each once.', $kindName));
            }
            $files[$kind->value] = $path;
        }

        $verifiers = [];
        foreach ([DatabaseRoleKind::Owner, DatabaseRoleKind::Runtime, DatabaseRoleKind::Backup] as $kind) {
            $role = $kind->roleIn($model);
            if ($role === null) {
                continue;
            }
            if (!isset($files[$kind->value])) {
                return null;
            }
            $password = DatabaseCredentialFile::passwordFor($role, $files[$kind->value]);
            $verifiers[$role->value] = ScramSha256Verifier::derive($password);
            $password->wipe();
        }

        return $verifiers;
    }
}
