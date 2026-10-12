<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\Backup\Config\BackupConfigLoader;
use Vortos\Backup\PgBackRest\PgBackRestBackupType;
use Vortos\Backup\PgBackRest\PgBackRestClient;
use Vortos\Backup\PgBackRest\PgBackRestDrill;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryInspector;
use Vortos\Backup\PgBackRest\PgBackRestRepositoryStatus;

/**
 * Drives pgBackRest by hand, the same way the backup worker does: inside the database container
 * through the Docker API proxy. `status` is the repository probe's own assessment.
 *
 * Output is written, not logged: production hides info-level logs, and an operator must see the result.
 * Exit code 0 only when the action succeeded (for `status`: the repository is healthy).
 */
#[AsCommand(name: 'backup:pgbackrest', description: 'pgBackRest: status | info | check | backup --type=full|diff | verify | drill')]
final class BackupPgBackRestCommand extends Command
{
    public function __construct(
        private readonly ?PgBackRestClient $client,
        private readonly ?PgBackRestDrill $drill,
        private readonly PgBackRestRepositoryInspector $inspector,
        private readonly BackupConfigLoader $loader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'status, info, check, backup, verify or drill');
        $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'backup type: full or diff', 'diff');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string) $input->getArgument('action');

        if ($this->client === null || $this->drill === null) {
            $output->writeln('<error>pgBackRest is not wired here: it needs ->pgBackRest(...) in config/backup.php and the Docker API proxy (VORTOS_BACKUP_DRILL_DOCKER_HOST).</error>');

            return 2;
        }

        try {
            return match ($action) {
                'status' => $this->status($output),
                'info' => $this->info($output),
                'check' => $this->done($output, 'check passed: the server archives into the stanza', fn () => $this->client->check()),
                'backup' => $this->backup($input, $output),
                'verify' => $this->done($output, 'verify passed: every repository file matches its checksum', fn () => $this->client->verify()),
                'drill' => $this->runDrill($output),
                default => throw new \InvalidArgumentException(sprintf('Unknown action "%s".', $action)),
            };
        } catch (Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }
    }

    private function status(OutputInterface $output): int
    {
        $assessment = $this->inspector->assess();
        $output->writeln((string) json_encode($assessment->toDetail(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $assessment->status === PgBackRestRepositoryStatus::Healthy ? Command::SUCCESS : Command::FAILURE;
    }

    private function info(OutputInterface $output): int
    {
        \assert($this->client !== null);
        $info = $this->client->info();
        $output->writeln(sprintf('stanza %s: %s (code %d), cipher %s, archive %s .. %s', $info->stanza, $info->statusMessage, $info->statusCode, $info->cipher ?? 'none', $info->archiveMin ?? '-', $info->archiveMax ?? '-'));
        foreach ($info->backups as $backup) {
            $output->writeln(sprintf(
                '  %-4s %s  %s  %d MiB data, %d MiB added%s',
                $backup->type->value,
                $backup->label,
                $backup->stoppedAt->format('Y-m-d H:i:s'),
                intdiv($backup->databaseBytes, 1 << 20),
                intdiv($backup->repositoryDeltaBytes, 1 << 20),
                $backup->error ? '  CHECKSUM ERROR' : '',
            ));
        }

        return $info->isOk() ? Command::SUCCESS : Command::FAILURE;
    }

    private function backup(InputInterface $input, OutputInterface $output): int
    {
        \assert($this->client !== null);
        $type = PgBackRestBackupType::tryFrom((string) $input->getOption('type'));
        if ($type === null || $type === PgBackRestBackupType::Incremental) {
            throw new \InvalidArgumentException('--type must be full or diff.');
        }

        $this->client->backup($type);

        return $this->info($output);
    }

    private function runDrill(OutputInterface $output): int
    {
        \assert($this->drill !== null);
        $report = $this->drill->run($this->loader->environment());
        $output->writeln((string) json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $report->restored() ? Command::SUCCESS : Command::FAILURE;
    }

    private function done(OutputInterface $output, string $message, callable $action): int
    {
        $action();
        $output->writeln($message);

        return Command::SUCCESS;
    }
}
