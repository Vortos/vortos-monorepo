<?php

declare(strict_types=1);

namespace Vortos\PersistenceMongo\Command;

use MongoDB\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\PersistenceMongo\Schema\MongoIndexAttributeScanner;

/**
 * Ensures all declared MongoDB indexes exist on their collections.
 *
 * Reads #[MongoIndex] attributes from registered MongoReadRepository subclasses
 * and applies them idempotently via createIndex(). Safe to run on every deploy.
 *
 * ## Usage
 *
 *   php bin/console vortos:mongo:sync
 *   php bin/console vortos:mongo:sync --dry-run   # show what would be synced
 */
#[AsCommand(
    name: 'vortos:mongo:sync',
    description: 'Ensure all declared MongoDB indexes exist (idempotent)',
)]
final class MongoSyncCommand extends Command
{
    public function __construct(
        private readonly Client $client,
        private readonly string $databaseName,
        private readonly MongoIndexAttributeScanner $scanner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be synced without writing to MongoDB',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun  = (bool) $input->getOption('dry-run');
        $entries = $this->scanner->scan();

        $output->writeln('<info>Vortos MongoDB Index Sync</info>');
        $output->writeln('');

        if (empty($entries)) {
            $output->writeln('<comment>No repositories with #[MongoIndex] found.</comment>');
            return Command::SUCCESS;
        }

        $database         = $this->client->selectDatabase($this->databaseName);
        $totalIndexes     = 0;
        $totalCollections = 0;

        foreach ($entries as $entry) {
            $collection = $entry['collection'];
            $indexes    = $entry['indexes'];

            if (empty($indexes)) {
                $output->writeln(sprintf('  <fg=gray>⊘ No indexes declared:</>  %s', $collection));
                continue;
            }

            $output->writeln(sprintf('  <info>%s</info>', $collection));

            foreach ($indexes as $indexDef) {
                $key       = $indexDef['key'];
                $options   = $indexDef['options'];
                $keyLabel  = $this->formatKey($key);
                $flagLabel = $this->formatFlags($options);

                if (!$dryRun) {
                    $database->selectCollection($collection)->createIndex($key, $options);
                }

                $output->writeln(sprintf(
                    '    <info>✔%s</info> %s%s',
                    $dryRun ? ' [DRY RUN]' : '',
                    $keyLabel,
                    $flagLabel !== '' ? " <fg=gray>({$flagLabel})</>" : '',
                ));

                $totalIndexes++;
            }

            $totalCollections++;
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln(sprintf(
                '<comment>%d index(es) across %d collection(s) would be synced.</comment>',
                $totalIndexes,
                $totalCollections,
            ));
        } else {
            $output->writeln(sprintf(
                '<info>✔ %d index(es) ensured across %d collection(s).</info>',
                $totalIndexes,
                $totalCollections,
            ));
        }

        return Command::SUCCESS;
    }

    /** @param array<string, int|string> $key */
    private function formatKey(array $key): string
    {
        $parts = [];
        foreach ($key as $field => $direction) {
            $parts[] = $direction === -1 ? "{$field} DESC" : $field;
        }

        return implode(', ', $parts);
    }

    /** @param array<string, mixed> $options */
    private function formatFlags(array $options): string
    {
        $flags = [];

        if (!empty($options['unique'])) {
            $flags[] = 'unique';
        }

        if (!empty($options['sparse'])) {
            $flags[] = 'sparse';
        }

        if (isset($options['expireAfterSeconds'])) {
            $flags[] = 'TTL=' . $options['expireAfterSeconds'] . 's';
        }

        return implode(', ', $flags);
    }
}
