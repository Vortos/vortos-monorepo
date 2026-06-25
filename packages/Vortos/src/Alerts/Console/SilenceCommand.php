<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use InvalidArgumentException;
use Vortos\Alerts\Escalation\MaintenanceSilence;
use Vortos\Alerts\Escalation\MaintenanceSilenceStoreInterface;

/** Creates a time-boxed, audited maintenance silence (never open-ended — enforced by the VO). */
#[AsCommand(
    name: 'vortos:alerts:silence',
    description: 'Create a time-boxed maintenance silence for a rule (or "*" for all rules)',
)]
final class SilenceCommand extends Command
{
    public function __construct(
        private readonly MaintenanceSilenceStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('rule-id', InputArgument::REQUIRED, 'Rule id to silence, or "*" for all')
            ->addArgument('duration-minutes', InputArgument::REQUIRED, 'How long the silence lasts, in minutes')
            ->addOption('created-by', null, InputOption::VALUE_REQUIRED, 'Identity of the operator creating the silence', 'cli')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Why this silence exists', 'planned maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $durationMinutes = (int) $input->getArgument('duration-minutes');

        try {
            $silence = new MaintenanceSilence(
                id: bin2hex(random_bytes(16)),
                ruleId: (string) $input->getArgument('rule-id'),
                startsAt: $now,
                expiresAt: $now->modify("+{$durationMinutes} minutes"),
                createdBy: (string) $input->getOption('created-by'),
                reason: (string) $input->getOption('reason'),
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->store->add($silence);

        $io->success(sprintf('Silence %s created, expires %s.', $silence->id, $silence->expiresAt->format(DateTimeImmutable::ATOM)));

        return Command::SUCCESS;
    }
}
