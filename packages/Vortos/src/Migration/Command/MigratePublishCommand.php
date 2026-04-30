<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\ModuleStubScanner;

/**
 * Converts Vortos module SQL stubs into Doctrine migration classes.
 *
 * ## What it does
 *
 * Scans every module in packages/Vortos/src/ for SQL files under Resources/migrations/.
 * For each stub not already published, it generates a Doctrine AbstractMigration class
 * in migrations/ and records the mapping in migrations/.vortos-published.json.
 *
 * ## Idempotent
 *
 * The manifest at migrations/.vortos-published.json tracks stub → class mappings.
 * Re-running the command skips already-published stubs and never overwrites existing files.
 *
 * ## After publishing
 *
 * Run vortos:migrate to apply the newly generated classes.
 *
 * ## Adding new module migrations
 *
 * Module authors simply drop an SQL file in:
 *   packages/Vortos/src/{Module}/Resources/migrations/NNN_description.sql
 *
 * Running vortos:migrate:publish (or checking vortos:migrate:status) picks it up automatically.
 */
#[AsCommand(
    name: 'vortos:migrate:publish',
    description: 'Convert module SQL stubs into Doctrine migration classes',
)]
final class MigratePublishCommand extends Command
{
    private const MANIFEST_FILE      = 'migrations/.vortos-published.json';
    private const MIGRATION_NAMESPACE = 'App\\Migrations';

    public function __construct(
        private readonly ModuleStubScanner $scanner,
        private readonly MigrationClassGenerator $generator,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be published without writing files',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun  = (bool) $input->getOption('dry-run');
        $stubs   = $this->scanner->scan();
        $manifest = $this->loadManifest();

        if (empty($stubs)) {
            $output->writeln('<comment>No module migration stubs found.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln('<info>Vortos Migration Publisher</info>');
        $output->writeln('');

        $migrationsDir  = $this->projectDir . '/migrations';
        $published      = 0;
        $skipped        = 0;
        $baseTimestamp  = (int) (new \DateTimeImmutable())->format('YmdHis');

        foreach ($stubs as $stub) {
            if (isset($manifest[$stub['relative']])) {
                $output->writeln(sprintf(
                    '  <fg=gray>⊘ Skipped   (already published):</> %s/%s',
                    $stub['module'],
                    $stub['filename'],
                ));
                $skipped++;
                continue;
            }

            // Find the next unused timestamp to avoid collisions when multiple stubs
            // are published in the same second or across multiple publish runs.
            $offset = 0;
            do {
                $className = $this->generator->buildClassName((string) ($baseTimestamp + $published + $offset));
                $filePath  = $migrationsDir . '/' . $className . '.php';
                $offset++;
            } while (file_exists($filePath));

            $fqcn = self::MIGRATION_NAMESPACE . '\\' . $className;

            $description = $this->generator->descriptionFromFilename($stub['filename']);
            $sql         = (string) file_get_contents($stub['path']);
            $content     = $this->generator->generateFromSql($className, self::MIGRATION_NAMESPACE, $description, $sql);

            if (!$dryRun) {
                if (!is_dir($migrationsDir)) {
                    mkdir($migrationsDir, 0755, true);
                }

                file_put_contents($filePath, $content);

                $manifest[$stub['relative']] = [
                    'class'        => $fqcn,
                    'published_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ];
            }

            $output->writeln(sprintf(
                '  <info>✔ Published%s:</info> migrations/%s.php  <fg=gray>(from %s/%s)</>',
                $dryRun ? ' [DRY RUN]' : '',
                $className,
                $stub['module'],
                $stub['filename'],
            ));

            $published++;
        }

        if (!$dryRun && $published > 0) {
            $this->saveManifest($manifest);
        }

        $output->writeln('');

        if ($published === 0 && $skipped > 0) {
            $output->writeln('<info>All module stubs are already published.</info>');
        } elseif ($published > 0) {
            $output->writeln(sprintf(
                '<info>✔ Published %d migration(s).</info> Run <info>vortos:migrate</info> to apply.',
                $published,
            ));
        }

        return Command::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function loadManifest(): array
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return $data['published'] ?? [];
    }

    /** @param array<string, mixed> $published */
    private function saveManifest(array $published): void
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;
        $data = [
            'version'   => 1,
            'published' => $published,
        ];

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
