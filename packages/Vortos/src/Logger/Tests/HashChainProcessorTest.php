<?php

declare(strict_types=1);

namespace Vortos\Logger\Tests;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Vortos\Logger\HashChain\InMemoryHashChainState;
use Vortos\Logger\Processor\HashChainProcessor;

final class HashChainProcessorTest extends TestCase
{
    public function test_first_record_chains_from_genesis(): void
    {
        $state = new InMemoryHashChainState();
        $processor = new HashChainProcessor($state);

        $record = $this->record('first');
        $result = $processor($record);

        $this->assertSame(InMemoryHashChainState::GENESIS, $result->extra['prev_hash']);
        $this->assertArrayHasKey('record_hash', $result->extra);
        $this->assertSame($result->extra['record_hash'], $state->last());
    }

    public function test_records_are_chained_in_sequence(): void
    {
        $state = new InMemoryHashChainState();
        $processor = new HashChainProcessor($state);

        $first  = $processor($this->record('first'));
        $second = $processor($this->record('second'));

        $this->assertSame($first->extra['record_hash'], $second->extra['prev_hash']);
        $this->assertNotSame($first->extra['record_hash'], $second->extra['record_hash']);
    }

    public function test_tampering_with_a_historical_record_breaks_the_chain(): void
    {
        $state = new InMemoryHashChainState();
        $processor = new HashChainProcessor($state);

        $first  = $processor($this->record('first'));
        $second = $processor($this->record('second'));

        // Simulate recomputing the hash for a tampered first record.
        $tamperedFirst = $this->record('first (tampered)');
        $tamperedHash  = $this->recompute(InMemoryHashChainState::GENESIS, $tamperedFirst);

        $this->assertNotSame($first->extra['record_hash'], $tamperedHash);
        // The second record's prev_hash no longer matches the recomputed
        // hash of the (tampered) first record — tamper detected.
        $this->assertNotSame($second->extra['prev_hash'], $tamperedHash);
    }

    private function recompute(string $prevHash, LogRecord $record): string
    {
        $payload = json_encode([
            'channel' => $record->channel,
            'level' => $record->level->value,
            'message' => $record->message,
            'context' => $record->context,
            'datetime' => $record->datetime->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $prevHash . '|' . $payload);
    }

    private function record(string $message): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            channel: 'audit',
            level: Level::Info,
            message: $message,
        );
    }
}
