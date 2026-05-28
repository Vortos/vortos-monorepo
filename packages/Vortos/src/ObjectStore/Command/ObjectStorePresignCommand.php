<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\ObjectStore\Contract\ObjectStoreInterface;
use Vortos\ObjectStore\ValueObject\TemporaryUploadUrlOptions;

#[AsCommand(name: 'vortos:object-store:presign', description: 'Create a temporary download or direct-upload URL')]
final class ObjectStorePresignCommand extends Command
{
    public function __construct(private readonly ObjectStoreInterface $objectStore)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('key', InputArgument::REQUIRED, 'Object key')
            ->addOption('upload', null, InputOption::VALUE_NONE, 'Generate a signed PUT upload URL')
            ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'TTL in seconds', '900')
            ->addOption('content-type', null, InputOption::VALUE_REQUIRED, 'Required upload content type')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, 'Maximum upload size in bytes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = (string) $input->getArgument('key');
        $ttl = max(1, (int) $input->getOption('ttl'));

        try {
            if ((bool) $input->getOption('upload')) {
                $url = $this->objectStore->temporaryUploadUrl($key, TemporaryUploadUrlOptions::forDirectUpload(
                    ttlSeconds: $ttl,
                    contentType: $input->getOption('content-type') !== null ? (string) $input->getOption('content-type') : null,
                    maxSizeBytes: $input->getOption('max-size') !== null ? (int) $input->getOption('max-size') : null,
                ))->url();
            } else {
                $url = $this->objectStore->temporaryDownloadUrl(
                    $key,
                    (new \DateTimeImmutable())->modify(sprintf('+%d seconds', $ttl)),
                );
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln($url->url());

        return Command::SUCCESS;
    }
}
