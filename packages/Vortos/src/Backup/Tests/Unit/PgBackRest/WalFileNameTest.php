<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\PgBackRest\WalFileName;

final class WalFileNameTest extends TestCase
{
    private const MIB_2 = 2 * 1024 * 1024;

    /** Production, 2 MiB segments: the full backup's stop LSN lies in the file pgBackRest named. */
    public function testTheFileHoldingAnLsnIsTheOnePgBackRestNames(): void
    {
        self::assertSame('0000000100000015000003C4', WalFileName::containing('15/78801330', 1, self::MIB_2)->name);
        self::assertSame('000000010000000000000001', WalFileName::containing('0/1000028', 1, 16 * 1024 * 1024)->name);
    }

    public function testDistanceDependsOnTheSegmentSize(): void
    {
        $a = WalFileName::parse('0000000100000015000003C4');

        self::assertSame(7, WalFileName::parse('0000000100000015000003CB')->sequence(self::MIB_2) - $a->sequence(self::MIB_2));
        // Across a log boundary: 2048 segments per log at 2 MiB, 256 at 16 MiB.
        self::assertSame(2048 - 0x3C4 + 2, WalFileName::parse('000000010000001600000002')->sequence(self::MIB_2) - $a->sequence(self::MIB_2));
        self::assertSame(256 + 2, WalFileName::parse('000000010000001600000002')->sequence(16 << 20) - WalFileName::parse('000000010000001500000000')->sequence(16 << 20));
    }

    public function testRefusesWhatIsNotAWalFileOrASegmentSize(): void
    {
        foreach (['00000002.history', '0000000100000015000003C4.partial', 'zz'] as $bad) {
            try {
                WalFileName::parse($bad);
                self::fail($bad . ' parsed');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        WalFileName::parse('0000000100000015000003C4')->sequence(3 * 1024 * 1024);
    }
}
