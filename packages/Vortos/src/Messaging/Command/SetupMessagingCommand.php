<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Publishes outbox and dead letter SQL migration files to the user's project.
 *
 * The SQL files ship inside the messaging package at:
 *   Vortos/src/Messaging/Resources/migrations/001_vortos_outbox.sql
 *   Vortos/src/Messaging/Resources/migrations/002_vortos_failed_messages.sql
 *
 * This command copies them to the user's project migrations directory.
 * It does NOT execute the SQL — the developer runs the files manually
 * against their write database after reviewing them.
 *
 * ## Idempotent
 *
 * If a file already exists at the destination, it is skipped — never overwritten.
 * The user may have customised the SQL (e.g. changed table name, added columns).
 * Their changes are preserved.
 *
 * ## Usage
 *
 *   php bin/console vortos:setup:messaging
 *   php bin/console vortos:setup:messaging --output-dir=database/migrations
 *
 * ## After running
 *
 * Execute the published SQL files against your write_db:
 *
 *   psql -U postgres -d squaura -f migrations/001_vortos_outbox.sql
 *   psql -U postgres -d squaura -f migrations/002_vortos_failed_messages.sql
 */
#[AsCommand(
    name: 'vortos:setup:messaging',
    description: 'Publish outbox and dead letter SQL migration files to your project',
)]
final class SetupMessagingCommand extends Command
{
    public function __construct(
       #[Autowire('%kernel.project_dir%')] private string $projectDir
    ){
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'output-dir',
            null,
            InputOption::VALUE_OPTIONAL,
            'Directory to publish migration files to, relative to project root',
            'migrations',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = $this->projectDir . '/' . $input->getOption('output-dir');

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $sourceDir = __DIR__ . '/../Resources/migrations';

        $files = [
            '001_vortos_outbox.sql',
            '002_vortos_failed_messages.sql',
        ];

        $output->writeln('<info>Vortos Messaging Setup</info>');
        $output->writeln('');

        foreach ($files as $filename) {
            $source = $sourceDir . '/' . $filename;
            $destination = $outputDir . '/' . $filename;

            if (file_exists($destination)) {
                $output->writeln(sprintf(
                    '  <comment>⊘ Skipped (already exists):</comment> %s',
                    $filename,
                ));
                continue;
            }

            copy($source, $destination);

            $output->writeln(sprintf(
                '  <info>✔ Published:</info> %s',
                $filename,
            ));
        }

        $output->writeln('');
        $output->writeln('<info>Next step:</info> Run these files against your write database:');
        $output->writeln('');
        $output->writeln(sprintf(
            '  psql -U postgres -d your_db -f %s/001_vortos_outbox.sql',
            $input->getOption('output-dir'),
        ));
        $output->writeln(sprintf(
            '  psql -U postgres -d your_db -f %s/002_vortos_failed_messages.sql',
            $input->getOption('output-dir'),
        ));
        $output->writeln('');

        return Command::SUCCESS;
    }
}
