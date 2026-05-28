<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command;

use Aws\SesV2\SesV2Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Displays the current AWS SES sending quota and usage for the last 24 hours.
 *
 * Usage:
 *   bin/console vortos:ses:quota
 */
#[AsCommand(
    name:        'vortos:ses:quota',
    description: 'Show the AWS SES sending quota and usage for the last 24 hours.',
)]
final class SesQuotaCommand extends Command
{
    public function __construct(private readonly SesV2Client $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->client->getSendQuota();
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to fetch SES quota: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $max24h    = (float) ($result['Max24HourSend']   ?? 0);
        $sent24h   = (float) ($result['SentLast24Hours'] ?? 0);
        $maxRate   = (float) ($result['MaxSendRate']     ?? 0);
        $remaining = max(0.0, $max24h - $sent24h);
        $usedPct   = $max24h > 0 ? round($sent24h / $max24h * 100, 1) : 0.0;

        $io->title('AWS SES Sending Quota');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Max 24-hour send',     number_format($max24h)],
                ['Sent last 24 hours',   number_format($sent24h)],
                ['Remaining',            number_format($remaining)],
                ['Usage',                $usedPct . '%'],
                ['Max send rate (msg/s)', $maxRate],
            ],
        );

        return Command::SUCCESS;
    }
}
