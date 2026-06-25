<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Observability\Audit\AuditChainVerifier;
use Vortos\Observability\Audit\DeployAuditViewRepositoryInterface;

/**
 * `vortos:observability:audit:verify` — runs {@see AuditChainVerifier} over the
 * stored ledger for an env, exiting non-zero on the first broken link (Block 16,
 * §3.5 — the CI/forensics gate).
 */
#[AsCommand(name: 'vortos:observability:audit:verify', description: 'Verify the deploy audit ledger hash chain for an environment')]
final class AuditVerifyCommand extends Command
{
    public function __construct(
        private readonly DeployAuditViewRepositoryInterface $repository,
        private readonly AuditChainVerifier $verifier,
        private readonly string $hmacKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment to verify');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $json = (bool) $input->getOption('json');

        if ($env === '') {
            $output->writeln('<error>--env is required.</error>');

            return Command::FAILURE;
        }

        $entries = $this->repository->findByEnv($env);
        $result = $this->verifier->verify($entries, $this->hmacKey);

        if ($json) {
            $output->writeln(json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        } elseif ($result->intact) {
            $output->writeln(sprintf('<info>Audit chain intact for "%s" (%d entries).</info>', $env, count($entries)));
        } else {
            $output->writeln(sprintf(
                '<error>Audit chain BROKEN for "%s" at sequence %d: %s</error>',
                $env,
                $result->brokenSequence,
                $result->reason,
            ));
        }

        return $result->intact ? Command::SUCCESS : Command::FAILURE;
    }
}
