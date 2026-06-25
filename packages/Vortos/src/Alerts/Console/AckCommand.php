<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Alerts\Escalation\Acknowledgement;
use Vortos\Alerts\Escalation\AckStoreInterface;
use Vortos\Alerts\Escalation\AckTokenException;
use Vortos\Alerts\Escalation\AckTokenSigner;

/** Acks an alert by signed token — the CLI path for the on-call responder. */
#[AsCommand(
    name: 'vortos:alerts:ack',
    description: 'Acknowledge an alert by its signed ack token',
)]
final class AckCommand extends Command
{
    public function __construct(
        private readonly AckTokenSigner $signer,
        private readonly AckStoreInterface $ackStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('token', InputArgument::REQUIRED, 'The signed ack token')
            ->addArgument('acked-by', InputArgument::REQUIRED, 'Identity of the acknowledging responder');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();

        try {
            $payload = $this->signer->verify((string) $input->getArgument('token'), $now);
        } catch (AckTokenException $e) {
            $io->error('Ack rejected: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->ackStore->record(new Acknowledgement(
            $payload->fingerprint,
            $payload->tier,
            (string) $input->getArgument('acked-by'),
            $now,
        ));

        $io->success(sprintf('Acknowledged fingerprint %s (tier %d).', $payload->fingerprint, $payload->tier));

        return Command::SUCCESS;
    }
}
