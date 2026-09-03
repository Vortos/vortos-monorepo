<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;

final class PitrRecoveryOutcomeTest extends TestCase
{
    private function outcome(int $segments, ?string $start, ?string $end): PitrRecoveryOutcome
    {
        return new PitrRecoveryOutcome($segments, $start, $end, '0000000100000000000000AA', true, 1200, '1');
    }

    public function testReplayRequiresBothASegmentAndAnAdvancingLsn(): void
    {
        self::assertTrue($this->outcome(3, '0/3000028', '0/5000100')->replayed());

        // A base backup that started up on its own: no segment was ever fetched.
        self::assertFalse($this->outcome(0, '0/3000028', '0/5000100')->replayed());

        // A segment was served but the log did not move — served is not the same as replayed.
        self::assertFalse($this->outcome(3, '0/5000100', '0/5000100')->replayed());
    }

    /**
     * LSNs are hex `X/Y` and must be compared as integers.
     *
     * `10/0` is far beyond `9/FFFFFFFF` but sorts BEFORE it as text, so a string comparison would
     * report a recovery that crossed that boundary as having gone backwards — turning a perfectly
     * good weekly drill red for arithmetic reasons, roughly once every 4 GB of WAL.
     */
    public function testLsnComparisonCrossesTheLogicalIdBoundary(): void
    {
        self::assertTrue($this->outcome(1, '9/FFFFFFFF', '10/00000000')->replayed());
        self::assertGreaterThan(
            PitrRecoveryOutcome::lsnToInt('9/FFFFFFFF'),
            PitrRecoveryOutcome::lsnToInt('10/00000000'),
        );
    }

    public function testMissingLsnsAreNotTreatedAsAReplay(): void
    {
        self::assertFalse($this->outcome(3, null, '0/5000100')->replayed());
        self::assertFalse($this->outcome(3, '0/3000028', null)->replayed());
    }

    /**
     * The summary is a wire format: the metrics collector reads the segment count back out of it.
     * Writing and parsing live in this class precisely so they cannot drift apart.
     */
    public function testSegmentCountRoundTripsThroughTheSummary(): void
    {
        $outcome = $this->outcome(147, '0/3000028', '0/5000100');

        self::assertSame(147, PitrRecoveryOutcome::segmentsFromSummary($outcome->summary()));
        self::assertNull(PitrRecoveryOutcome::segmentsFromSummary('no archived WAL for this environment'));
    }
}
