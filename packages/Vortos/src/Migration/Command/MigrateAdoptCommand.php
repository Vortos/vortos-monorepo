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
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationDriftFormatter;
use Vortos\Migration\Service\ModuleMigrationRegistry;

#[AsCommand(
    name: 'vortos:migrate:adopt',
    description: 'Mark verified existing schema as migrated without executing SQL',
)]
final class MigrateAdoptCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProvider $factoryProvider,
        private readonly ModuleMigrationRegistry $moduleRegistry,
        private readonly MigrationDriftDetector $driftDetector,
        private readonly MigrationDriftFormatter $driftFormatter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Migration version/class to adopt')
            ->addOption('all-compatible', null, InputOption::VALUE_NONE, 'Adopt all pending module migrations whose schema is compatible and already present')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Require compatible existing schema before adopting')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be adopted without writing migration metadata')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $allCompatible = (bool) $input->getOption('all-compatible');
        $versionInput = (string) ($input->getArgument('version') ?? '');

        if (!$allCompatible && $versionInput === '') {
            $output->writeln('<error>Specify a migration version or pass --all-compatible.</error>');
            return Command::FAILURE;
        }

        $available = $factory->getMigrationPlanCalculator()->getMigrations();
        $executed = $storage->getExecutedMigrations();
        $descriptors = $this->moduleRegistry->descriptorsByClass();
        $candidates = [];

        foreach ($available->getItems() as $migration) {
            $version = (string) $migration->getVersion();

            if ($executed->hasMigration($migration->getVersion())) {
                continue;
            }

            if (!isset($descriptors[$version])) {
                continue;
            }

            if ($allCompatible || $this->matchesVersion($version, $versionInput)) {
                $report = $this->driftDetector->detect($descriptors[$version]);
                $candidates[$version] = [$descriptors[$version], $report];
            }
        }

        if ($candidates === []) {
            $output->writeln('<comment>No pending module migration matched the adoption request.</comment>');
            return Command::SUCCESS;
        }

        $verify = (bool) $input->getOption('verify') || $allCompatible;
        $dryRun = (bool) $input->getOption('dry-run');
        $asJson = (bool) $input->getOption('json');
        $adoptable = [];
        $blocked = [];

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

        if ($asJson) {
            $output->writeln(json_encode([
                'dry_run' => $dryRun,
                'adoptable' => $this->jsonRows($adoptable),
                'blocked' => $this->jsonRows($blocked),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderRows('Adoptable migration(s)', $adoptable, $output);
            $this->renderRows('Blocked migration(s)', $blocked, $output);
        }

        if ($blocked !== [] || $adoptable === [] || $dryRun) {
            return $blocked === [] ? Command::SUCCESS : Command::FAILURE;
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
        foreach (array_keys($adoptable) as $version) {
            $result = new ExecutionResult(new Version($version), Direction::UP);
            $result->setExecutedAt($now);
            $storage->complete($result);
        }

        if (!$asJson) {
            $output->writeln(sprintf('<info>✔ Adopted %d migration(s).</info>', count($adoptable)));
        }

        return Command::SUCCESS;
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
                'module' => $descriptor->module(),
                'source' => $descriptor->source(),
                'schema' => $this->driftFormatter->toArray($report, executed: false),
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
