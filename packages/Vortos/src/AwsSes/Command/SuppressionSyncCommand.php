<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command;

use Aws\SesV2\SesV2Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\AwsSes\Contract\SuppressionListInterface;
use Vortos\AwsSes\Suppression\SuppressionReason;
use Vortos\AwsSes\ValueObject\EmailAddress;

/**
 * Syncs the AWS account-level suppression list to the local database.
 *
 * Calls SES V2 ListSuppressedDestinations (paginated) and upserts every entry
 * into the local suppression list table. Exits non-zero on API error.
 *
 * Usage:
 *   bin/console vortos:ses:suppression:sync
 *   bin/console vortos:ses:suppression:sync --dry-run
 */
#[AsCommand(
    name:        'vortos:ses:suppression:sync',
    description: 'Sync the AWS SES account suppression list to the local database.',
)]
final class SuppressionSyncCommand extends Command
{
    public function __construct(
        private readonly SesV2Client $client,
        private readonly SuppressionListInterface $suppressionList,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and count entries without writing to the database.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('SES Suppression List Sync');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be written.');
        }

        $synced = 0;
        $token  = null;

        try {
            do {
                $params = ['PageSize' => 100];
                if ($token !== null) {
                    $params['NextToken'] = $token;
                }

                $response = $this->client->listSuppressedDestinations($params);
                $entries  = $response['SuppressedDestinationSummaries'] ?? [];
                $token    = $response['NextToken'] ?? null;

                foreach ($entries as $entry) {
                    $address = new EmailAddress((string) $entry['EmailAddress']);
                    $reason  = $this->mapReason((string) ($entry['Reason'] ?? 'MANUAL'));

                    if (!$dryRun) {
                        $this->suppressionList->suppress($address, $reason);
                    }

                    ++$synced;
                }
            } while ($token !== null);
        } catch (\Throwable $e) {
            $io->error(sprintf('AWS API error: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $action = $dryRun ? 'Found' : 'Synced';
        $io->success(sprintf('%s %d suppressed address(es).', $action, $synced));

        return Command::SUCCESS;
    }

    private function mapReason(string $sesReason): SuppressionReason
    {
        return match (strtoupper($sesReason)) {
            'BOUNCE'    => SuppressionReason::Bounce,
            'COMPLAINT' => SuppressionReason::Complaint,
            default     => SuppressionReason::Manual,
        };
    }
}
