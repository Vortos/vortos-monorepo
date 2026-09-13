<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\DR\ObjectiveStatus;
use Vortos\Backup\DR\RecoveryObjectivesInspector;

/**
 * Prints the recovery point exposure and projected recovery time against the declared objectives.
 *
 * Output is written, not logged: production hides info-level logs, and an operator checking an
 * objective must see the numbers.
 *
 * Exit code: 0 = both met, 1 = either breached or projected to breach, 2 = could not measure or no
 * objective declared. 2 is NOT success — an unmeasured objective is not a kept one.
 */
#[AsCommand(name: 'backup:objectives', description: 'Measure RPO exposure and projected RTO against the declared recovery objectives.')]
final class BackupObjectivesCommand extends Command
{
    public function __construct(private readonly RecoveryObjectivesInspector $inspector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $point = $this->inspector->recoveryPoint();
        $time = $this->inspector->recoveryTime();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['rpo' => $point->toDetail(), 'rto' => $time->toDetail()],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));
        } else {
            $output->writeln(sprintf('RPO [%s] %s', $point->status->value, $point->reason));
            $output->writeln(sprintf('RTO [%s] %s', $time->status->value, $time->reason));
            if ($time->projectedSeconds !== null) {
                $output->writeln(sprintf(
                    '    now ~%ds, before next base %s; %d segments since base at %s; %.1f segments/h; %.1f ms/segment + %d ms fixed (drill %s)',
                    $time->projectedSeconds,
                    $time->projectedAtNextAnchorSeconds !== null ? '~' . $time->projectedAtNextAnchorSeconds . 's' : 'n/a (no base scheduled)',
                    $time->segmentsSinceAnchor ?? 0,
                    $time->anchorAt?->format(DATE_ATOM) ?? '?',
                    $time->segmentsPerHour ?? 0.0,
                    $time->replayMsPerSegment ?? 0.0,
                    $time->fixedMs ?? 0,
                    $time->evidenceDrillId ?? '?',
                ));
            }
        }

        $statuses = [$point->status, $time->status];

        foreach ($statuses as $status) {
            if ($status->pages()) {
                return Command::FAILURE;
            }
        }

        foreach ($statuses as $status) {
            if ($status !== ObjectiveStatus::Met) {
                return 2;
            }
        }

        return Command::SUCCESS;
    }
}
