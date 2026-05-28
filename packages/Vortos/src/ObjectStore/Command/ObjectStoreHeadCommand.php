<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;

#[AsCommand(name: 'vortos:object-store:head', description: 'Show metadata for an object-store key')]
final class ObjectStoreHeadCommand extends Command
{
    public function __construct(private readonly ObjectStoreInterface $objectStore)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Object key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $metadata = $this->objectStore->head((string) $input->getArgument('key'));
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->table(['Field', 'Value'], [
            ['Key', $metadata->key()->value()],
            ['Size', (string) $metadata->size()],
            ['Content type', $metadata->contentType()?->value() ?? ''],
            ['ETag', $metadata->etag() ?? ''],
            ['Last modified', $metadata->lastModified()?->format(DATE_ATOM) ?? ''],
        ]);

        return Command::SUCCESS;
    }
}
