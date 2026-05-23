<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\MigrationDriftFormatterInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;
use Vortos\Migration\Service\UserMigrationOwnershipExtractorInterface;

#[AsCommand(
    name: 'vortos:migrate:adopt',
    description: 'Mark verified existing schema as migrated without executing SQL',
)]
final class MigrateAdoptCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly ModuleMigrationRegistryInterface $moduleRegistry,
        private readonly MigrationDriftDetectorInterface $driftDetector,
        private readonly MigrationDriftFormatterInterface $driftFormatter,
        private readonly UserMigrationOwnershipExtractorInterface $ownershipExtractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Migration version/class to adopt')
            ->addOption('all-compatible', null, InputOption::VALUE_NONE, 'Adopt all pending migrations whose schema is compatible and already present')
            ->addOption('module-only', null, InputOption::VALUE_NONE, 'Restrict adoption to framework module migrations only (skip user-authored migrations)')
            ->addOption('allow-unverified', null, InputOption::VALUE_NONE, 'Allow adopting user-authored migrations that use raw SQL and cannot be auto-verified')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Require compatible existing schema before adopting (implied by --all-compatible)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be adopted without writing migration metadata')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $allCompatible   = (bool) $input->getOption('all-compatible');
        $moduleOnly      = (bool) $input->getOption('module-only');
        $allowUnverified = (bool) $input->getOption('allow-unverified');
        $versionInput    = (string) ($input->getArgument('version') ?? '');

        if (!$allCompatible && $versionInput === '') {
            $output->writeln('<error>Specify a migration version or pass --all-compatible.</error>');
            return Command::FAILURE;
        }

        $available   = $factory->getMigrationPlanCalculator()->getMigrations();
        $executed    = $storage->getExecutedMigrations();
        $descriptors = $this->moduleRegistry->descriptorsByClass();
        $candidates  = [];
        $userCandidates = [];

        foreach ($available->getItems() as $migration) {
            $version = (string) $migration->getVersion();

            if ($executed->hasMigration($migration->getVersion())) {
                continue;
            }

            if (!isset($descriptors[$version])) {
                if (!$moduleOnly && ($allCompatible || $this->matchesVersion($version, $versionInput))) {
                    $ownership = $this->ownershipExtractor->extract($version);

                    if ($ownership !== null) {
                        $syntheticDescriptor = new ModuleMigrationDescriptor(
                            source: 'user',
                            class: $version,
                            module: 'user',
                            filename: basename(str_replace('\\', '/', $version)) . '.php',
                            ownership: $ownership,
                        );
                        $report = $this->driftDetector->detect($syntheticDescriptor);
                        $candidates[$version] = [$syntheticDescriptor, $report];
                    } else {
                        $userCandidates[$version] = true;
                    }
                }
                continue;
            }

            if ($allCompatible || $this->matchesVersion($version, $versionInput)) {
                $report = $this->driftDetector->detect($descriptors[$version]);
                $candidates[$version] = [$descriptors[$version], $report];
            }
        }

        if ($candidates === [] && $userCandidates === []) {
            $output->writeln('<comment>No pending migration matched the adoption request.</comment>');
            return Command::SUCCESS;
        }

        $verify  = (bool) $input->getOption('verify') || $allCompatible;
        $dryRun  = (bool) $input->getOption('dry-run');
        $asJson  = (bool) $input->getOption('json');
        $adoptable = [];
        $blocked   = [];

        foreach ($candidates as $version => [$descriptor, $report]) {
            if ($verify && $report->status() !== MigrationDriftReport::CompatibleExisting) {
                $blocked[$version] = [$descriptor, $report];
                continue;
            }

            if (!$verify && $report->status() === MigrationDriftReport::Partial) {
                $blocked[$version] = [$descriptor, $report];
                continue;
            }

            $adoptable[$version] = [$descriptor, $report];
        }

        $userVersions  = array_keys($userCandidates);
        $hasUnverified = $userVersions !== [] && !$allowUnverified;

        if ($asJson) {
            $output->writeln(json_encode([
                'dry_run'         => $dryRun,
                'adoptable'       => $this->jsonRows($adoptable),
                'blocked'         => $this->jsonRows($blocked),
                'user_unverified' => $userVersions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $blocked === [] && !$hasUnverified ? Command::SUCCESS : Command::FAILURE;
        }

        $this->renderRows('Adoptable migration(s)', $adoptable, $output);
        $this->renderRows('Blocked migration(s)', $blocked, $output);

        if ($userVersions !== []) {
            $this->renderUserRows($userVersions, $allowUnverified, $allCompatible, $output);
        }

        if ($blocked !== [] || $dryRun) {
            return $blocked === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($hasUnverified) {
            return Command::FAILURE;
        }

        if ($adoptable === [] && $userVersions === []) {
            return Command::SUCCESS;
        }

        if (!(bool) $input->getOption('force') && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Mark these migrations as executed? [y/N]</question> ', false))) {
                $output->writeln('<comment>Adoption aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $now = new \DateTimeImmutable();
        $versionsToRecord = array_merge(array_keys($adoptable), $allowUnverified ? $userVersions : []);

        foreach ($versionsToRecord as $version) {
            $result = new ExecutionResult(new Version($version), Direction::UP);
            $result->setExecutedAt($now);
            $storage->complete($result);
        }

        $verifiedCount   = count($adoptable);
        $unverifiedCount = $allowUnverified ? count($userVersions) : 0;
        $totalCount      = $verifiedCount + $unverifiedCount;

        if ($unverifiedCount > 0) {
            $output->writeln(sprintf(
                '<info>✔ Adopted %d migration(s) (%d verified, %d unverified).</info>',
                $totalCount,
                $verifiedCount,
                $unverifiedCount,
            ));
            $output->writeln('');
            $output->writeln('  Unverified migration(s) adopted on trust — no schema check was performed.');
            $output->writeln('  If you discover a mismatch, recover with:');
            $output->writeln('');
            foreach ($userVersions as $uv) {
                $output->writeln(sprintf('    <comment>php vortos migrate:unadopt %s</comment>', $uv));
            }
        } else {
            $output->writeln(sprintf('<info>✔ Adopted %d migration(s).</info>', $totalCount));
        }

        return Command::SUCCESS;
    }

    /** @param list<string> $versions */
    private function renderUserRows(array $versions, bool $allowUnverified, bool $allCompatible, OutputInterface $output): void
    {
        $output->writeln('<comment>Unverified migration(s) — raw SQL, cannot auto-verify:</comment>');
        $output->writeln('');

        foreach ($versions as $version) {
            $output->writeln(sprintf('  <comment>→</comment> %s <fg=gray>(user-authored, drift not checked)</>', $version));
        }

        $output->writeln('');

        if (!$allowUnverified) {
            $output->writeln('  Vortos cannot verify these migrations. Manually confirm your schema is');
            $output->writeln('  correct, then re-run with <comment>--allow-unverified</comment>:');
            $output->writeln('');
            $flag = $allCompatible ? '--all-compatible --allow-unverified' : '--allow-unverified';
            $output->writeln(sprintf('    <comment>php vortos migrate:adopt %s</comment>', $flag));
            $output->writeln('');
        }
    }

    private function matchesVersion(string $version, string $input): bool
    {
        return $version === $input || str_ends_with($version, '\\' . $input) || basename(str_replace('\\', '/', $version)) === $input;
    }

    /**
     * @param array<string, array{0: ModuleMigrationDescriptor, 1: MigrationDriftReport}> $rows
     * @return list<array<string, mixed>>
     */
    private function jsonRows(array $rows): array
    {
        $data = [];

        foreach ($rows as $version => [$descriptor, $report]) {
            $data[] = [
                'version' => $version,
                'module'  => $descriptor->module(),
                'source'  => $descriptor->source(),
                'schema'  => $this->driftFormatter->toArray($report, executed: false),
            ];
        }

        return $data;
    }

    /**
     * @param array<string, array{0: ModuleMigrationDescriptor, 1: MigrationDriftReport}> $rows
     */
    private function renderRows(string $title, array $rows, OutputInterface $output): void
    {
        if ($rows === []) {
            return;
        }

        $output->writeln('<info>' . $title . ':</info>');

        foreach ($rows as $version => [$descriptor, $report]) {
            $output->writeln(sprintf(
                '  <comment>→</comment> %s <fg=gray>(%s/%s, %s)</>',
                $version,
                $descriptor->module(),
                $descriptor->filename(),
                $this->driftFormatter->label($report, executed: false),
            ));
        }

        $output->writeln('');
    }
}
