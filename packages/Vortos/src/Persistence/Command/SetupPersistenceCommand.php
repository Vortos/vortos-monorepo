<?php

declare(strict_types=1);

namespace Vortos\Persistence\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\PersistenceMongo\Read\MongoReadRepository;

/**
 * Verifies persistence connections and ensures read-side indexes.
 *
 * Safe to run on every deploy — all operations are idempotent.
 * Does NOT create tables, run migrations, or publish SQL files.
 *
 * ## What this command does
 *
 *   1. Iterates all services tagged 'vortos.read_repository'
 *   2. For each MongoReadRepository, calls ensureIndexes()
 *   3. Reports success or failure per repository
 *
 * ## How to register your read repositories for index management
 *
 *   $services->set(UserReadRepository::class)
 *       ->arg('$client', service(MongoDB\Client::class))
 *       ->arg('$databaseName', '%vortos.persistence.mongo.database_name%')
 *       ->tag('vortos.read_repository');
 *
 * ## Usage
 *
 *   php bin/console vortos:setup:persistence
 */
#[AsCommand(
    name: 'vortos:setup:persistence',
    description: 'Verify persistence connections and ensure read-side indexes',
)]
final class SetupPersistenceCommand extends Command
{
    /**
     * @param iterable<MongoReadRepository> $readRepositories All services tagged 'vortos.read_repository'
     */
    public function __construct(
        private iterable $readRepositories,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Vortos Persistence Setup</info>');
        $output->writeln('');

        $count = 0;

        foreach ($this->readRepositories as $repository) {
            if (!$repository instanceof MongoReadRepository) {
                continue;
            }

            $collectionName = $repository->getCollectionName();
            $indexCount = $repository->getIndexCount();

            if ($indexCount === 0) {
                $output->writeln(sprintf(
                    '  <comment>⊘ No indexes declared:</comment> %s',
                    $collectionName,
                ));
                continue;
            }

            $repository->ensureIndexes();

            $output->writeln(sprintf(
                '  <info>✔ Indexes ensured (%d):</info> %s',
                $indexCount,
                $collectionName,
            ));

            $count++;
        }

        $output->writeln('');

        if ($count === 0) {
            $output->writeln(
                '<comment>No read repositories found. Tag your repositories with vortos.read_repository.</comment>'
            );
        } else {
            $output->writeln(sprintf('<info>Done. %d collection(s) processed.</info>', $count));
        }

        return Command::SUCCESS;
    }
}
