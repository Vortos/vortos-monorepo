<?php

declare(strict_types=1);

namespace Vortos\Migration\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Service\ConnectionDependencyFactoryProviderInterface;
use Vortos\Migration\Service\ReversibilityVerifier;

#[AsCommand(
    name: 'vortos:migrate:down-verify',
    description: 'Verify migrations are reversible by running up→down→up in a disposable database',
)]
final class MigrateDownVerifyCommand extends Command
{
    private const DB_PREFIX = 'vortos_downverify_';

    private const PHASE_LABELS = [
        'prerequisite' => 'PREREQUISITE UP',
        'up'           => 'UP',
        'down'         => 'DOWN',
        're-up'        => 'RE-UP',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ConnectionDependencyFactoryProviderInterface $factories,
        private readonly ReversibilityVerifier $verifier,
        private readonly MigrationArtifactFactoryInterface $artifactFactory,
        private readonly ?MigrationSafetyAnalyzerInterface $analyzer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Number of last migrations to verify', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'dev';
        if ($env === 'prod') {
            $output->writeln('<error>down-verify refuses to run in production environment.</error>');
            return Command::FAILURE;
        }

        $appDbName = $this->connection->getDatabase();
        $json = (bool) $input->getOption('json');
        $count = max(0, (int) $input->getOption('count'));

        $disposableDbName = self::DB_PREFIX . bin2hex(random_bytes(8));

        $adminConnection = $this->getAdminConnection();
        $this->sweepOrphans($adminConnection, $output, $json);

        try {
            $adminConnection->executeStatement(sprintf(
                'CREATE DATABASE %s',
                $adminConnection->quoteIdentifier($disposableDbName),
            ));

            if (!$json) {
                $output->writeln(sprintf('Created disposable database: %s', $disposableDbName));
            }

            return $this->runVerification($disposableDbName, $count, $output, $json);
        } catch (\Throwable $e) {
            $this->outputError($output, $json, $e->getMessage());
            return Command::FAILURE;
        } finally {
            try {
                $adminConnection->executeStatement(sprintf(
                    'DROP DATABASE IF EXISTS %s',
                    $adminConnection->quoteIdentifier($disposableDbName),
                ));
                if (!$json) {
                    $output->writeln(sprintf('Dropped disposable database: %s', $disposableDbName));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<comment>Warning: failed to drop disposable DB: %s</comment>', $e->getMessage()));
            }

            $adminConnection->close();
        }
    }

    private function runVerification(string $dbName, int $count, OutputInterface $output, bool $json): int
    {
        $disposableConnection = $this->createDisposableConnection($dbName);

        try {
            // The project's migrations, run by Doctrine against the disposable database exactly as
            // vortos:migrate runs them — see ReversibilityVerifier for what extracting SQL text got wrong.
            $result = $this->verifier->verify(
                fn () => $this->factories->forConnection($disposableConnection),
                $count,
                $json ? null : static function (string $message) use ($output): void { $output->writeln($message); },
            );

            if (!$result->ok) {
                $this->outputError($output, $json, sprintf(
                    '%s failed for %s: %s',
                    self::PHASE_LABELS[$result->phase] ?? strtoupper((string) $result->phase),
                    $this->shortVersion((string) $result->version),
                    $result->reason,
                ), $result->phase, $result->version);

                return Command::FAILURE;
            }

            if ($this->analyzeDownSql($result->verified, $output, $json) > 0) {
                return Command::FAILURE;
            }

            $this->outputResult($output, $json, true, $result->verifiedCount);

            return Command::SUCCESS;
        } finally {
            $disposableConnection->close();
        }
    }

    /** @param list<string> $versions */
    private function analyzeDownSql(array $versions, OutputInterface $output, bool $json): int
    {
        if ($this->analyzer === null) {
            return 0;
        }

        $issues = 0;

        foreach ($versions as $version) {
            $artifact = $this->artifactFactory->fromClass($version);

            if ($artifact->downSql === []) {
                continue;
            }

            $downArtifact = $this->artifactFactory->fromRawSql(
                version: $version . '::down',
                upSql: $artifact->downSql,
                phase: $artifact->phase,
                hasAllowFullTableRewrite: $artifact->hasAllowFullTableRewrite,
                hasAllowNonIdempotentConcurrent: $artifact->hasAllowNonIdempotentConcurrent,
            );

            $result = $this->analyzer->analyze($downArtifact, null);

            if ($result->hasErrors()) {
                $issues++;
                if (!$json) {
                    $output->writeln(sprintf('  <error>✘</error> %s (down migration has lock-safety issues)', $this->shortVersion($version)));
                    foreach ($result->errors() as $d) {
                        $output->writeln(sprintf('      [%s] %s', $d->ruleId, $d->message));
                    }
                }
            }
        }

        return $issues;
    }

    private function getAdminConnection(): Connection
    {
        $params = $this->connection->getParams();
        $params['dbname'] = 'postgres';

        return DriverManager::getConnection($params);
    }

    private function createDisposableConnection(string $dbName): Connection
    {
        $params = $this->connection->getParams();
        $params['dbname'] = $dbName;

        return DriverManager::getConnection($params);
    }

    private function sweepOrphans(Connection $adminConnection, OutputInterface $output, bool $json): void
    {
        try {
            $databases = $adminConnection->fetchFirstColumn(
                "SELECT datname FROM pg_database WHERE datname LIKE 'vortos_downverify_%'",
            );

            foreach ($databases as $db) {
                $adminConnection->executeStatement(sprintf(
                    'DROP DATABASE IF EXISTS %s',
                    $adminConnection->quoteIdentifier((string) $db),
                ));
                if (!$json) {
                    $output->writeln(sprintf('<comment>Swept orphaned disposable DB: %s</comment>', $db));
                }
            }
        } catch (\Throwable) {
        }
    }

    private function outputError(
        OutputInterface $output,
        bool $json,
        string $message,
        ?string $phase = null,
        ?string $version = null,
    ): void {
        if ($json) {
            $data = ['ok' => false, 'error' => $message];
            if ($phase !== null) {
                $data['failedPhase'] = $phase;
            }
            if ($version !== null) {
                $data['failedVersion'] = $this->shortVersion($version);
            }
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(sprintf('<error>%s</error>', $message));
        }
    }

    private function outputResult(OutputInterface $output, bool $json, bool $ok, int $verified): void
    {
        if ($json) {
            $output->writeln(json_encode(['ok' => $ok, 'verified' => $verified], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($verified === 0) {
            $output->writeln('<comment>No migrations to verify.</comment>');
        } else {
            $output->writeln(sprintf('<info>All %d migration(s) verified: up→down→up succeeded.</info>', $verified));
        }
    }

    private function shortVersion(string $version): string
    {
        $parts = explode('\\', $version);

        return end($parts);
    }
}
