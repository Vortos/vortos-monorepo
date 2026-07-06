<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Domain\EngineResolver;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Doctor\BackupToolchainInspector;
use Vortos\Backup\Doctor\ToolchainReport;
use Vortos\Backup\Port\BackupStoreRegistry;

/**
 * Fail-closed backup preflight (STAGE-F-1). Asserts, before the first real backup, that:
 *   - a backup engine is resolvable (`--engine` or `VORTOS_BACKUP_ENGINE`), never guessed;
 *   - the engine's client binaries are on PATH and new enough to operate against the server;
 *   - the configured backup store resolves.
 *
 * Runs standalone in the backup sidecar image; the same toolchain probe also gates `deploy:doctor`
 * via {@see \Vortos\Deploy\Preflight\Check\BackupToolchainCheck}.
 */
#[AsCommand(name: 'backup:doctor', description: 'Preflight the backup toolchain (engine binaries + store) fail-closed.')]
final class BackupDoctorCommand extends Command
{
    public function __construct(
        private readonly EngineResolver $engineResolver,
        private readonly BackupToolchainInspector $inspector,
        private readonly BackupStoreRegistry $stores,
        private readonly string $storeKey,
        private readonly ?Connection $connection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo (defaults to VORTOS_BACKUP_ENGINE)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');

        try {
            $engine = $this->engineResolver->resolve($this->optionalString($input->getOption('engine')));
        } catch (BackupException $e) {
            return $this->fail($output, $json, 'engine', $e->getMessage());
        }

        $report = $this->inspector->inspect($engine, $this->serverMajor($engine));
        $storeOk = $this->storeResolves();

        if ($json) {
            $output->writeln((string) json_encode(
                ['ok' => $report->isSatisfied() && $storeOk, 'store' => ['key' => $this->storeKey, 'resolved' => $storeOk], 'toolchain' => $report->toArray()],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $report->isSatisfied() && $storeOk ? self::SUCCESS : self::FAILURE;
        }

        $this->render($output, $engine, $report, $storeOk);

        return $report->isSatisfied() && $storeOk ? self::SUCCESS : self::FAILURE;
    }

    private function render(OutputInterface $output, DatabaseEngine $engine, ToolchainReport $report, bool $storeOk): void
    {
        $output->writeln(sprintf('<info>Backup engine:</info> %s%s', $engine->value, $report->serverMajor !== null ? sprintf(' (server major %d)', $report->serverMajor) : ''));

        foreach ($report->findings as $finding) {
            $mark = $finding->isFailure() ? '<error>✗</error>' : '<info>✓</info>';
            $output->writeln(sprintf('  %s %s — %s', $mark, $finding->name, $finding->message));
        }

        $output->writeln($storeOk
            ? sprintf('  <info>✓</info> store "%s" resolves', $this->storeKey)
            : sprintf('  <error>✗ store "%s" does not resolve</error>', $this->storeKey));

        $output->writeln($report->isSatisfied() && $storeOk
            ? '<info>Backup preflight passed.</info>'
            : '<error>Backup preflight failed — fix the above before running a backup.</error>');
    }

    private function fail(OutputInterface $output, bool $json, string $scope, string $message): int
    {
        if ($json) {
            $output->writeln((string) json_encode(['ok' => false, 'scope' => $scope, 'error' => $message], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('<error>✗ %s</error>', $message));
        }

        return self::FAILURE;
    }

    private function storeResolves(): bool
    {
        try {
            $this->stores->store($this->storeKey);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The Postgres server major, read best-effort — a version gate needs it, but an unreachable DB
     * must not fail the toolchain doctor (presence is still enforced; only version gating relaxes).
     */
    private function serverMajor(DatabaseEngine $engine): ?int
    {
        if ($engine !== DatabaseEngine::Postgres || $this->connection === null) {
            return null;
        }

        try {
            $num = (int) $this->connection->executeQuery('SHOW server_version_num')->fetchOne();

            return $num > 0 ? intdiv($num, 10000) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
