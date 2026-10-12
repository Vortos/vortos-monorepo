<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\PgBackRest;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\PgBackRest\PgBackRestBackupType;
use Vortos\Backup\PgBackRest\PgBackRestInfo;
use Vortos\Backup\Tests\Support\PgBackRestInfoFixture;

final class PgBackRestInfoTest extends TestCase
{
    public function testParsesWhatProductionPrinted(): void
    {
        $info = PgBackRestInfo::fromJson(PgBackRestInfoFixture::production()->json(), 'sqoura');

        self::assertTrue($info->isOk());
        self::assertSame('aes-256-cbc', $info->cipher);
        self::assertSame('0000000100000015000003C1', $info->archiveMin);
        self::assertSame('0000000100000015000003CB', $info->archiveMax);

        $full = $info->newest();
        self::assertNotNull($full);
        self::assertSame('20261012-151549F', $full->label);
        self::assertSame(PgBackRestBackupType::Full, $full->type);
        self::assertSame('2026-10-12 15:15:59', $full->stoppedAt->format('Y-m-d H:i:s'));
        self::assertSame('0000000100000015000003C4', $full->walStop);
        self::assertSame('15/78801330', $full->lsnStop);
        self::assertSame(371002462, $full->databaseBytes);
        self::assertSame(52227936, $full->repositoryDeltaBytes);
    }

    public function testNewestSkipsBackupsWithChecksumErrorsAndFiltersByType(): void
    {
        $info = PgBackRestInfo::fromJson(
            PgBackRestInfoFixture::production()
                ->withBackup('20261012-151549F_20261013-020000D', 'diff', 1791892800, 1791892810, '0000000100000015000004C4')
                ->withBackup('20261012-151549F_20261014-020000D', 'diff', 1791979200, 1791979210, '0000000100000015000005C4', error: true)
                ->json(),
            'sqoura',
        );

        self::assertSame('20261012-151549F_20261013-020000D', $info->newest()?->label, 'a set pgBackRest flagged with a page checksum error is never the restore point');
        self::assertSame('20261012-151549F', $info->newest(PgBackRestBackupType::Full)?->label);
    }

    public function testANonOkStatusIsReportedInPgBackRestsOwnWords(): void
    {
        $info = PgBackRestInfo::fromJson(PgBackRestInfoFixture::production()->withStatus(2, 'no valid backups')->json(), 'sqoura');

        self::assertFalse($info->isOk());
        self::assertSame('no valid backups', $info->statusMessage);
    }

    public function testAnotherStanzaOrNonJsonIsRefused(): void
    {
        try {
            PgBackRestInfo::fromJson(PgBackRestInfoFixture::production()->json(), 'other');
            self::fail('a missing stanza must not read as an empty repository');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not list stanza "other"', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        PgBackRestInfo::fromJson('P00 ERROR: [028]', 'sqoura');
    }
}
