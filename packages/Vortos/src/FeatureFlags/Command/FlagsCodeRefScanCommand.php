<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Scan the codebase for feature flag references (Block 12).
 *
 * Finds flag names referenced in:
 *   PHP  — isEnabled('name'), useFlag('name'), #[RequiresFlag('name')], getFlag('name')
 *   JS/TS — isEnabled('name'), useFlag('name'), useFeatureFlag('name'), getFlag('name')
 *
 * Reports:
 *   - flags that appear in code but are not in storage (orphaned refs → dead code)
 *   - flags that exist in storage but have no code refs (zombie flags → stale)
 *
 * Usage:
 *   php bin/console vortos:flags:code-ref-scan --path=src
 */
#[AsCommand(name: 'vortos:flags:code-ref-scan', description: 'Scan codebase for feature flag references and report orphans/zombies')]
final class FlagsCodeRefScanCommand extends Command
{
    /** Patterns that extract a flag name from a call/annotation. */
    private const PATTERNS = [
        // PHP & JS/TS: isEnabled('name'), getFlag('name'), useFlag('name'), useFeatureFlag('name')
        '/(?:isEnabled|getFlag|useFlag|useFeatureFlag)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/u',
        // PHP attribute: #[RequiresFlag('name')]
        '/#\[RequiresFlag\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\]/u',
    ];

    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path',      null, InputOption::VALUE_REQUIRED, 'Root directory to scan', '.')
            ->addOption('ext',       null, InputOption::VALUE_REQUIRED, 'Comma-separated file extensions to scan', 'php,ts,tsx,js,jsx')
            ->addOption('json',      null, InputOption::VALUE_NONE,     'Output as JSON')
            ->addOption('zombies',   null, InputOption::VALUE_NONE,     'Show zombie flags only (in storage, not in code)')
            ->addOption('orphans',   null, InputOption::VALUE_NONE,     'Show orphaned refs only (in code, not in storage)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootPath  = (string) ($input->getOption('path') ?? '.');
        $exts      = array_map('trim', explode(',', (string) ($input->getOption('ext') ?? 'php,ts,tsx,js,jsx')));
        $onlyZomb  = (bool) $input->getOption('zombies');
        $onlyOrph  = (bool) $input->getOption('orphans');

        if (!is_dir($rootPath)) {
            $output->writeln(sprintf('<error>Path "%s" is not a directory.</error>', $rootPath));
            return Command::FAILURE;
        }

        // Collect all known flags from storage.
        $flags      = $this->storage->findAll();
        $knownNames = [];
        foreach ($flags as $flag) {
            $knownNames[$flag->name] = $flag;
        }

        // Scan files for flag name references.
        $refs = $this->scanDirectory($rootPath, $exts);

        // Build report.
        $orphaned = [];  // in code but not in storage
        $zombie   = [];  // in storage but not in code

        foreach ($refs as $name => $locations) {
            if (!isset($knownNames[$name])) {
                $orphaned[$name] = $locations;
            }
        }

        foreach ($knownNames as $name => $flag) {
            if (!isset($refs[$name])) {
                $zombie[$name] = [
                    'lifecycle' => $flag->lifecycle->value,
                    'owner'     => $flag->owner,
                    'project'   => $flag->projectId,
                ];
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'scanned_files'  => count($this->listFiles($rootPath, $exts)),
                'known_flags'    => count($knownNames),
                'refs_found'     => count($refs),
                'orphaned_refs'  => $orphaned,
                'zombie_flags'   => $zombie,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>Code Ref Scan</> <fg=gray>path: %s</>',
            realpath($rootPath) ?: $rootPath,
        ));
        $output->writeln(sprintf(
            ' <fg=gray>%d known flags · %d unique refs in code · %d orphaned refs · %d zombie flags</>',
            count($knownNames),
            count($refs),
            count($orphaned),
            count($zombie),
        ));
        $output->writeln('');

        if (!$onlyZomb && count($orphaned) > 0) {
            $output->writeln(' <fg=red;options=bold>Orphaned references</> <fg=gray>(in code, not in storage — dead code)</>');
            foreach ($orphaned as $name => $locs) {
                $output->writeln(sprintf('  <fg=red>%-40s</> <fg=gray>%s</>', $name, implode(', ', array_slice($locs, 0, 3))));
            }
            $output->writeln('');
        }

        if (!$onlyOrph && count($zombie) > 0) {
            $output->writeln(' <fg=yellow;options=bold>Zombie flags</> <fg=gray>(in storage, no code refs — candidates for cleanup)</>');
            foreach ($zombie as $name => $meta) {
                $output->writeln(sprintf(
                    '  <fg=yellow>%-40s</> <fg=gray>lifecycle: %s · owner: %s</>',
                    $name,
                    $meta['lifecycle'],
                    $meta['owner'] ?? '—',
                ));
            }
            $output->writeln('');
        }

        if (count($orphaned) === 0 && count($zombie) === 0) {
            $output->writeln(' <fg=green>All flag references are healthy — no orphans or zombies found.</>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }

    /** @return array<string, string[]> flagName → [file:line, ...] */
    private function scanDirectory(string $rootPath, array $exts): array
    {
        $refs = [];

        foreach ($this->listFiles($rootPath, $exts) as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);
            foreach ($lines as $lineNo => $line) {
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match_all($pattern, $line, $matches)) {
                        foreach ($matches[1] as $name) {
                            $location          = sprintf('%s:%d', $file, $lineNo + 1);
                            $refs[$name][]     = $location;
                        }
                    }
                }
            }
        }

        return $refs;
    }

    /** @return string[] */
    private function listFiles(string $rootPath, array $exts): array
    {
        $files   = [];
        $extSet  = array_flip($exts);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (isset($extSet[$ext])) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
