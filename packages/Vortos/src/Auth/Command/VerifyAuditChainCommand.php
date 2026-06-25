<?php
declare(strict_types=1);

namespace Vortos\Auth\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Auth\Audit\Contract\AuditChainReaderInterface;
use Vortos\Auth\Audit\Integrity\AuthAuditChainVerifier;

#[AsCommand(
    name: 'vortos:auth:verify-audit-chain',
    description: 'Walks the hash-chained audit log and reports the first tamper, gap, or forged signature found.',
)]
final class VerifyAuditChainCommand extends Command
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly ?AuditChainReaderInterface $reader,
        private readonly AuthAuditChainVerifier $verifier,
        private readonly string $hmacKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->hmacKey === '') {
            $output->writeln('<comment>Chain integrity is disabled (audit_hmac_key not configured) — nothing to verify.</comment>');

            return Command::SUCCESS;
        }

        if ($this->reader === null) {
            $output->writeln(
                '<error>No AuditChainReaderInterface implementation registered for your audit store. '
                . 'Implement it (read entries back ordered by sequence) to enable chain verification.</error>',
            );

            return Command::FAILURE;
        }

        $afterSequence = 0;
        $prevHash = null;
        $checked = 0;

        while (true) {
            $entries = $this->reader->findChainedEntries($afterSequence, self::BATCH_SIZE);
            if ($entries === []) {
                break;
            }

            $result = $prevHash === null
                ? $this->verifier->verify($entries, $this->hmacKey)
                : $this->verifier->verify($entries, $this->hmacKey, $prevHash, $afterSequence + 1);

            $checked += count($entries);

            if (!$result->intact) {
                $output->writeln(sprintf(
                    '<error>Audit chain broken at sequence %d: %s (expected "%s", found "%s")</error>',
                    $result->brokenSequence,
                    $result->reason,
                    $result->expectedHash,
                    $result->actualHash,
                ));

                return Command::FAILURE;
            }

            $last = $entries[count($entries) - 1];
            $afterSequence = $last->sequence;
            $prevHash = $last->contentHash;
        }

        if ($checked === 0) {
            $output->writeln('<comment>No chained audit entries found.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Audit chain intact — %d entries verified, no tampering detected.</info>',
            $checked,
        ));

        return Command::SUCCESS;
    }
}
