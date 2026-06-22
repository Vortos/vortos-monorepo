<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

/**
 * Surface flags that are candidates for cleanup (Block 12).
 *
 * A flag is considered stale when ANY of:
 *   - lifecycle is Archived (kept for audit, should be reviewed for hard-delete)
 *   - lifecycle is Draft and updatedAt is older than --draft-days (default 30)
 *   - expiresAt is set and in the past (missed cleanup deadline)
 *   - enabled is false and not updated for --stale-days days (default 90, skip if --no-inactive)
 */
#[AsCommand(name: 'vortos:flags:staleness-report', description: 'List stale flags that are candidates for cleanup')]
final class FlagsStalenessReportCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json',         null, InputOption::VALUE_NONE,     'Output as JSON')
            ->addOption('stale-days',   null, InputOption::VALUE_REQUIRED, 'Days inactive before a disabled flag is stale', 90)
            ->addOption('draft-days',   null, InputOption::VALUE_REQUIRED, 'Days in Draft before considered stale', 30)
            ->addOption('no-inactive',  null, InputOption::VALUE_NONE,     'Skip inactive-flag staleness check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now        = new \DateTimeImmutable();
        $staleDays  = (int) ($input->getOption('stale-days') ?? 90);
        $draftDays  = (int) ($input->getOption('draft-days') ?? 30);
        $noInactive = (bool) $input->getOption('no-inactive');

        $flags  = $this->storage->findAll();
        $stale  = [];

        foreach ($flags as $flag) {
            $reasons = [];

            if ($flag->lifecycle === FlagLifecycleState::Archived) {
                $reasons[] = 'archived';
            }

            if ($flag->lifecycle === FlagLifecycleState::Draft) {
                $daysSinceUpdate = (int) $now->diff($flag->updatedAt)->days;
                if ($daysSinceUpdate >= $draftDays) {
                    $reasons[] = sprintf('draft for %d days', $daysSinceUpdate);
                }
            }

            if ($flag->expiresAt !== null && $flag->expiresAt <= $now) {
                $reasons[] = sprintf('expired %s', $flag->expiresAt->format('Y-m-d'));
            }

            if (!$noInactive && !$flag->enabled && $flag->lifecycle === FlagLifecycleState::Active) {
                $daysSinceUpdate = (int) $now->diff($flag->updatedAt)->days;
                if ($daysSinceUpdate >= $staleDays) {
                    $reasons[] = sprintf('disabled for %d days', $daysSinceUpdate);
                }
            }

            if ($reasons !== []) {
                $stale[] = [
                    'name'      => $flag->name,
                    'lifecycle' => $flag->lifecycle->value,
                    'owner'     => $flag->owner ?? '—',
                    'project'   => $flag->projectId,
                    'expires'   => $flag->expiresAt?->format('Y-m-d') ?? '—',
                    'updated'   => $flag->updatedAt->format('Y-m-d'),
                    'reasons'   => $reasons,
                ];
            }
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($stale, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>Stale Flags</> <fg=gray>(%d of %d)</>',
            count($stale),
            count($flags),
        ));
        $output->writeln('');

        if (empty($stale)) {
            $output->writeln(' <fg=green>No stale flags found. All flags are healthy.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $style = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($style);
        $table->setHeaders(['<fg=gray>Name</>', '<fg=gray>Lifecycle</>', '<fg=gray>Owner</>', '<fg=gray>Expires</>', '<fg=gray>Reasons</>']);

        foreach ($stale as $entry) {
            $lifecycleFg = match ($entry['lifecycle']) {
                'archived' => 'red',
                'draft'    => 'yellow',
                default    => 'white',
            };

            $table->addRow([
                sprintf('<fg=white>%s</>', $entry['name']),
                sprintf('<fg=%s>%s</>', $lifecycleFg, $entry['lifecycle']),
                sprintf('<fg=gray>%s</>', $entry['owner']),
                sprintf('<fg=yellow>%s</>', $entry['expires']),
                sprintf('<fg=red>%s</>', implode(', ', $entry['reasons'])),
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
