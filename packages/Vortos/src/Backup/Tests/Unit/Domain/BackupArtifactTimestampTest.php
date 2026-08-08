<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Tests\Support\ArtifactFactory;

/**
 * The catalog's timestamp encoding, which decides which backups retention deletes.
 *
 * Retention now asks the database for the WAL segments either side of a cut-off instead of reading
 * every artifact and comparing in PHP. That is what keeps the query bounded, and it moves one side
 * of the comparison into SQL — so the encoding written to the column and the encoding compared
 * against it have to agree exactly, on every driver, whatever the host's date.timezone is.
 *
 * They did not, and the integration suite could not see it: SQLite hands back the ATOM string
 * exactly as written, offset and all, so the round-trip is self-correcting there. Postgres parses
 * into a timestamp column and returns a naive `Y-m-d H:i:s` with the offset dropped — and a naive
 * string is read in whatever timezone the process happens to be in. These tests work on that string
 * form directly, because it is the one that carries the ambiguity and the one production uses.
 */
final class BackupArtifactTimestampTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    /**
     * The regression guard. A naive timestamp — what Postgres returns — is UTC, because that is
     * what was written, regardless of the timezone the process is running in.
     */
    public function test_a_naive_stored_timestamp_is_read_as_utc(): void
    {
        date_default_timezone_set('Asia/Colombo'); // +05:30

        $row = ArtifactFactory::at('2026-06-23T02:00:00+00:00')->toArray();
        // Postgres normalises into its timestamp column and gives the offset back to nobody.
        $row['created_at'] = '2026-06-23 02:00:00';

        $artifact = BackupArtifact::fromArray($row);

        self::assertSame(
            '2026-06-23T02:00:00+00:00',
            $artifact->createdAt->format(DATE_ATOM),
            'A naive stored timestamp drifted by the host offset — every retention comparison drifts with it.',
        );
    }

    /** An offset-bearing timestamp — what SQLite returns — keeps its own offset. */
    public function test_an_offset_bearing_stored_timestamp_keeps_its_instant(): void
    {
        date_default_timezone_set('Asia/Colombo');

        $row = ArtifactFactory::at('2026-06-23T02:00:00+00:00')->toArray();

        $artifact = BackupArtifact::fromArray($row);

        self::assertSame('2026-06-23T02:00:00+00:00', $artifact->createdAt->format(DATE_ATOM));
    }

    /** Encoding is UTC-normalised, so the stored form is fixed-width and sorts chronologically. */
    public function test_encoding_normalises_to_utc(): void
    {
        date_default_timezone_set('Asia/Colombo');

        $at = new DateTimeImmutable('2026-06-23 07:30:00', new DateTimeZone('Asia/Colombo'));

        self::assertSame('2026-06-23T02:00:00+00:00', BackupArtifact::encodeTimestamp($at));
    }

    /**
     * Write then read must land on the same instant under a non-UTC host — the property the
     * bounded retention query depends on and the old read-everything-and-filter path never needed.
     */
    public function test_round_trip_is_stable_under_a_non_utc_host(): void
    {
        date_default_timezone_set('Asia/Colombo');

        $original = ArtifactFactory::at('2026-06-23T02:00:00+00:00');
        $restored = BackupArtifact::fromArray($original->toArray());

        self::assertEquals($original->createdAt->getTimestamp(), $restored->createdAt->getTimestamp());
        self::assertSame(
            BackupArtifact::encodeTimestamp($original->createdAt),
            BackupArtifact::encodeTimestamp($restored->createdAt),
        );
    }
}
