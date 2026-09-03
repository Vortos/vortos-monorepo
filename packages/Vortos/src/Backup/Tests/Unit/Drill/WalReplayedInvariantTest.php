<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Drill\Check\WalReplayedInvariant;
use Vortos\Backup\Pitr\PitrRecoveryOutcome;
use Vortos\Backup\Pitr\PitrRecoveryRecorder;

/**
 * The check the whole point-in-time feature turns on: restoring a base backup and booting it is a
 * restore to the base's own instant, not point-in-time recovery — and it is indistinguishable from
 * the real thing to every other invariant.
 */
final class WalReplayedInvariantTest extends TestCase
{
    private function check(?PitrRecoveryOutcome $outcome, bool $requireEndOfWal = true): \Vortos\Backup\Drill\InvariantResult
    {
        $recorder = new PitrRecoveryRecorder();
        if ($outcome !== null) {
            $recorder->record($outcome);
        }

        return (new WalReplayedInvariant($recorder, $requireEndOfWal))->check([]);
    }

    public function testPassesOnARealReplayThatReachedTheEndOfTheArchive(): void
    {
        $result = $this->check(new PitrRecoveryOutcome(190, '0/3000028', '0/5000100', '0000000100000000000000BE', true, 94000, '1'));

        self::assertTrue($result->passed);
        self::assertStringContainsString('190 WAL segments replayed', $result->detail);
    }

    /**
     * The silent-green case this exists to remove.
     */
    public function testFailsWhenTheBaseStartedUpWithoutReplayingAnything(): void
    {
        $result = $this->check(new PitrRecoveryOutcome(0, '0/3000028', '0/3000028', null, false, 4000, '1'));

        self::assertFalse($result->passed);
        self::assertStringContainsString('no WAL segments were served', $result->detail);
    }

    public function testFailsWhenSegmentsWereServedButTheLogDidNotAdvance(): void
    {
        $result = $this->check(new PitrRecoveryOutcome(3, '0/5000100', '0/5000100', '0000000100000000000000AA', true, 9000, '1'));

        self::assertFalse($result->passed);
        self::assertStringContainsString('did not advance', $result->detail);
    }

    public function testFailsWhenRecoveryStoppedShortOfTheEndOfTheArchive(): void
    {
        $result = $this->check(new PitrRecoveryOutcome(5, '0/3000028', '0/4000000', '0000000100000000000000AA', false, 9000, '1'));

        self::assertFalse($result->passed);
        self::assertStringContainsString('before the archive was exhausted', $result->detail);
    }

    public function testEndOfArchiveCanBeWaivedForADeliberatelyEarlierTarget(): void
    {
        $result = $this->check(
            new PitrRecoveryOutcome(5, '0/3000028', '0/4000000', '0000000100000000000000AA', false, 9000, '1'),
            requireEndOfWal: false,
        );

        self::assertTrue($result->passed);
    }

    /**
     * No recording means the point-in-time path never ran. Passing here would be the exact vacuous
     * green the invariant exists to prevent.
     */
    public function testFailsWhenNoRecoveryWasRecordedAtAll(): void
    {
        $result = $this->check(null);

        self::assertFalse($result->passed);
        self::assertStringContainsString('did not perform a WAL replay', $result->detail);
    }
}
