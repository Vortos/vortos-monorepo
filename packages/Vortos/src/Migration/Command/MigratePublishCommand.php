<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Schema\ModuleSchemaProviderInterface;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
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
        private readonly ?ModuleSchemaProviderScanner $schemaScanner = null,
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
        $stubs   = $this->migrationSources();
        $manifest = $this->loadManifest();
        $manifestChanged = $this->loadManifestVersion() < 2;

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
        $hasUnpublished = $this->hasUnpublishedStubs($stubs, $manifest);

        if (!$dryRun && $hasUnpublished) {
            $this->assertPublishTargetWritable($migrationsDir);
        }

        foreach ($stubs as $stub) {
            $manifestKey = $this->manifestKeyFor($stub, $manifest);
            if ($manifestKey !== null) {
                if (!$dryRun && $this->enrichManifestEntry($manifest[$manifestKey], $stub)) {
                    $manifestChanged = true;
                }

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
            $content = isset($stub['provider'])
                ? $this->generator->generateFromSchemaProvider(
                    $className,
                    self::MIGRATION_NAMESPACE,
                    $stub['provider'],
                )
                : $this->generator->generateFromSql(
                    $className,
                    self::MIGRATION_NAMESPACE,
                    $description,
                    (string) file_get_contents($stub['path']),
                );

            if (!$dryRun) {
                if (!is_dir($migrationsDir)) {
                    mkdir($migrationsDir, 0755, true);
                }

                $this->writeFile($filePath, $content);

                $manifest[$stub['relative']] = [
                    'source_type'  => isset($stub['provider']) ? 'schema' : 'sql',
                    'class'        => $fqcn,
                    'published_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                    'module'       => $stub['module'],
                    'filename'     => $stub['filename'],
                    'objects'      => $stub['objects'] ?? ['tables' => [], 'indexes' => []],
                    'checksum'     => $this->checksum($stub['path']),
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

        if (!$dryRun && ($published > 0 || $manifestChanged)) {
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

    /**
     * @param list<array{relative: string}> $stubs
     * @param array<string, mixed> $manifest
     */
    private function hasUnpublishedStubs(array $stubs, array $manifest): bool
    {
        foreach ($stubs as $stub) {
            if ($this->manifestKeyFor($stub, $manifest) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     module: string,
     *     filename: string,
     *     path: string,
     *     relative: string,
     *     provider?: ModuleSchemaProviderInterface,
     *     objects?: array{tables: string[], indexes: string[]}
     * }>
     */
    private function migrationSources(): array
    {
        $sources = [];
        $providerSqlCounterparts = [];

        foreach ($this->schemaScanner?->scan() ?? [] as $schemaProvider) {
            /** @var ModuleSchemaProviderInterface $provider */
            $provider = $schemaProvider['provider'];
            $relative = $schemaProvider['relative'];
            $providerSqlCounterparts[$this->replaceExtension($relative, 'sql')] = true;

            $sources[] = [
                'module' => $schemaProvider['module'],
                'filename' => $schemaProvider['filename'],
                'path' => $schemaProvider['path'],
                'relative' => $relative,
                'provider' => $provider,
                'objects' => $provider->ownership()->toArray(),
            ];
        }

        foreach ($this->scanner->scan() as $sqlStub) {
            if (isset($providerSqlCounterparts[$sqlStub['relative']])) {
                continue;
            }

            $sources[] = $sqlStub + ['objects' => ['tables' => [], 'indexes' => []]];
        }

        usort($sources, static fn(array $a, array $b) => strcmp($a['filename'], $b['filename']));

        return $sources;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function manifestKeyFor(array $stub, array $manifest): ?string
    {
        if (isset($manifest[$stub['relative']])) {
            return $stub['relative'];
        }

        $legacySqlKey = $this->replaceExtension($stub['relative'], 'sql');

        return isset($stub['provider'], $manifest[$legacySqlKey]) ? $legacySqlKey : null;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $stub
     */
    private function enrichManifestEntry(array &$entry, array $stub): bool
    {
        $before = $entry;

        $entry['source_type'] ??= isset($stub['provider']) ? 'schema' : 'sql';
        $entry['module'] ??= $stub['module'];
        $entry['filename'] ??= $stub['filename'];
        $entry['objects'] ??= $stub['objects'] ?? ['tables' => [], 'indexes' => []];
        $entry['checksum'] ??= $this->checksum($stub['path']);

        return $entry !== $before;
    }

    private function replaceExtension(string $path, string $extension): string
    {
        return preg_replace('/\.[^.\/]+$/', '.' . $extension, $path) ?? $path;
    }

    private function checksum(string $path): string
    {
        return 'sha256:' . hash_file('sha256', $path);
    }

    private function assertPublishTargetWritable(string $migrationsDir): void
    {
        if (is_dir($migrationsDir) && !is_writable($migrationsDir)) {
            throw new \RuntimeException(sprintf(
                'Cannot publish migrations because "%s" is not writable.',
                $migrationsDir,
            ));
        }

        $manifestPath = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (file_exists($manifestPath) && !is_writable($manifestPath)) {
            throw new \RuntimeException(sprintf(
                'Cannot publish migrations because manifest "%s" is not writable. Fix file ownership/permissions before publishing.',
                $manifestPath,
            ));
        }

        if (!file_exists($manifestPath) && is_dir($migrationsDir) && !is_writable($migrationsDir)) {
            throw new \RuntimeException(sprintf(
                'Cannot create migration publish manifest in "%s" because the directory is not writable.',
                $migrationsDir,
            ));
        }
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

    private function loadManifestVersion(): int
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            return 2;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return isset($data['version']) && is_int($data['version']) ? $data['version'] : 1;
    }

    /** @param array<string, mixed> $published */
    private function saveManifest(array $published): void
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;
        $data = [
            'version'   => 2,
            'published' => $published,
        ];

        $this->writeFile($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function writeFile(string $path, string $content): void
    {
        $bytes = @file_put_contents($path, $content, LOCK_EX);

        if ($bytes === false) {
            throw new \RuntimeException(sprintf(
                'Failed to write "%s". Check file ownership and permissions.',
                $path,
            ));
        }
    }
}
