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
use Vortos\ObjectStore\Contract\ServerSideMultipartMaintenanceInterface;

#[AsCommand(name: 'vortos:object-store:multipart', description: 'List and abort trusted server-side multipart uploads')]
final class ObjectStoreMultipartCommand extends Command
{
    public function __construct(private readonly ServerSideMultipartMaintenanceInterface $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action: list | abort | abort-stale')
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED, 'Only include multipart uploads under this key prefix.')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Object key for abort.')
            ->addOption('upload-id', null, InputOption::VALUE_REQUIRED, 'Multipart upload ID for abort.')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Abort uploads initiated before this relative time or absolute datetime.', '-24 hours')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Required for abort and abort-stale.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview abort-stale without aborting uploads.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = (string) $input->getArgument('action');

        try {
            return match ($action) {
                'list' => $this->list($io, (string) ($input->getOption('prefix') ?? '')),
                'abort' => $this->abort($input, $io),
                'abort-stale' => $this->abortStale($input, $io),
                default => $this->invalidAction($io, $action),
            };
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function list(SymfonyStyle $io, string $prefix): int
    {
        $uploads = $this->maintenance->list($prefix !== '' ? $prefix : null);

        if ($uploads === []) {
            $io->info('No multipart uploads found.');
            return Command::SUCCESS;
        }

        $io->table(['Key', 'Upload ID', 'Initiated'], array_map(
            static fn($upload): array => [$upload->key, $upload->uploadId, $upload->initiatedAt->format(DATE_ATOM)],
            $uploads,
        ));

        return Command::SUCCESS;
    }

    private function abort(InputInterface $input, SymfonyStyle $io): int
    {
        if (!(bool) $input->getOption('confirm')) {
            $io->error('abort requires --confirm.');
            return Command::FAILURE;
        }

        $key = (string) ($input->getOption('key') ?? '');
        $uploadId = (string) ($input->getOption('upload-id') ?? '');
        if ($key === '' || $uploadId === '') {
            $io->error('abort requires --key and --upload-id.');
            return Command::FAILURE;
        }

        $this->maintenance->abort($key, $uploadId);
        $io->success(sprintf('Aborted multipart upload %s for %s.', $uploadId, $key));

        return Command::SUCCESS;
    }

    private function abortStale(InputInterface $input, SymfonyStyle $io): int
    {
        $olderThan = new \DateTimeImmutable((string) $input->getOption('older-than'));
        $prefix = (string) ($input->getOption('prefix') ?? '');

        if ((bool) $input->getOption('dry-run')) {
            $uploads = array_filter(
                $this->maintenance->list($prefix !== '' ? $prefix : null),
                static fn($upload): bool => $upload->initiatedAt < $olderThan,
            );
            $io->success(sprintf('%d stale multipart upload(s) would be aborted.', count($uploads)));
            return Command::SUCCESS;
        }

        if (!(bool) $input->getOption('confirm')) {
            $io->error('abort-stale requires --confirm unless --dry-run is used.');
            return Command::FAILURE;
        }

        $aborted = $this->maintenance->abortStale($olderThan, $prefix !== '' ? $prefix : null);
        $io->success(sprintf('Aborted %d stale multipart upload(s).', $aborted));

        return Command::SUCCESS;
    }

    private function invalidAction(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Invalid action "%s". Use: list, abort, abort-stale.', $action));
        return Command::FAILURE;
    }
}
