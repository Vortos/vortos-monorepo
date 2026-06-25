<?php
declare(strict_types=1);

namespace Vortos\Auth\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditChainReaderInterface;
use Vortos\Auth\Audit\Integrity\AuthAuditChainVerifier;
use Vortos\Auth\Audit\Integrity\AuthAuditHashChain;
use Vortos\Auth\Command\VerifyAuditChainCommand;

final class VerifyAuditChainCommandTest extends TestCase
{
    private AuthAuditHashChain $chain;
    private string $hmacKey;

    protected function setUp(): void
    {
        $this->chain = new AuthAuditHashChain();
        $this->hmacKey = bin2hex(random_bytes(32));
    }

    /** @return list<AuditEntry> */
    private function buildChain(int $count): array
    {
        $entries = [];
        $prevHash = AuthAuditHashChain::GENESIS_HASH;

        for ($i = 0; $i < $count; $i++) {
            $entry = AuditEntry::create("user-{$i}", "action.{$i}");
            $chained = $this->chain->chain($entry, $i, $prevHash, $this->hmacKey);
            $entries[] = $chained;
            $prevHash = $chained->contentHash;
        }

        return $entries;
    }

    private function runCommand(?AuditChainReaderInterface $reader, string $hmacKey): CommandTester
    {
        $command = new VerifyAuditChainCommand($reader, new AuthAuditChainVerifier($this->chain), $hmacKey);
        $application = new Application();
        $application->add($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    public function test_reports_disabled_when_no_hmac_key_configured(): void
    {
        $tester = $this->runCommand(null, '');

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function test_fails_when_no_reader_registered(): void
    {
        $tester = $this->runCommand(null, $this->hmacKey);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('No AuditChainReaderInterface', $tester->getDisplay());
    }

    public function test_reports_intact_chain(): void
    {
        $entries = $this->buildChain(5);
        $reader = $this->createMock(AuditChainReaderInterface::class);
        $reader->method('findChainedEntries')
            ->willReturnOnConsecutiveCalls($entries, []);

        $tester = $this->runCommand($reader, $this->hmacKey);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('intact', $tester->getDisplay());
        $this->assertStringContainsString('5 entries verified', $tester->getDisplay());
    }

    public function test_reports_no_entries(): void
    {
        $reader = $this->createMock(AuditChainReaderInterface::class);
        $reader->method('findChainedEntries')->willReturn([]);

        $tester = $this->runCommand($reader, $this->hmacKey);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('No chained audit entries', $tester->getDisplay());
    }

    public function test_detects_tampering_within_a_single_page(): void
    {
        $entries = $this->buildChain(3);
        $tampered = new AuditEntry(
            id: $entries[1]->id,
            userId: 'hacker',
            action: $entries[1]->action,
            resourceId: $entries[1]->resourceId,
            ipAddress: $entries[1]->ipAddress,
            userAgent: $entries[1]->userAgent,
            occurredAt: $entries[1]->occurredAt,
            metadata: $entries[1]->metadata,
            sequence: $entries[1]->sequence,
            prevHash: $entries[1]->prevHash,
            contentHash: $entries[1]->contentHash,
            signature: $entries[1]->signature,
        );
        $entries[1] = $tampered;

        $reader = $this->createMock(AuditChainReaderInterface::class);
        $reader->method('findChainedEntries')->willReturnOnConsecutiveCalls($entries, []);

        $tester = $this->runCommand($reader, $this->hmacKey);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Audit chain broken at sequence 1', $tester->getDisplay());
    }

    public function test_verifies_continuity_across_paginated_reads(): void
    {
        $entries = $this->buildChain(4);
        $reader = $this->createMock(AuditChainReaderInterface::class);
        $reader->method('findChainedEntries')->willReturnOnConsecutiveCalls(
            [$entries[0], $entries[1]],
            [$entries[2], $entries[3]],
            [],
        );

        $tester = $this->runCommand($reader, $this->hmacKey);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('4 entries verified', $tester->getDisplay());
    }

    public function test_detects_break_at_page_boundary(): void
    {
        $entries = $this->buildChain(4);
        $detached = $this->chain->chain(
            AuditEntry::create('hacker', 'forged'),
            2,
            'aaaa' . str_repeat('0', 60),
            $this->hmacKey,
        );
        $entries[2] = $detached;

        $reader = $this->createMock(AuditChainReaderInterface::class);
        $reader->method('findChainedEntries')->willReturnOnConsecutiveCalls(
            [$entries[0], $entries[1]],
            [$entries[2], $entries[3]],
            [],
        );

        $tester = $this->runCommand($reader, $this->hmacKey);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('prevHash', $tester->getDisplay());
    }
}
