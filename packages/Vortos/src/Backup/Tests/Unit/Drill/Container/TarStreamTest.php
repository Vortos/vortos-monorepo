<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Drill\Container;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Drill\Container\TarStream;

/**
 * The tar writer is the only piece of this drill that speaks a wire format nobody else validates:
 * Docker either accepts the archive or returns an opaque error, so a malformed header shows up as
 * "the drill container is empty" rather than as a checksum failure.
 */
final class TarStreamTest extends TestCase
{
    public function testEntriesCarryNameSizeModeAndOwnership(): void
    {
        $tar = (new TarStream())
            ->addFile('recovery.signal', '', 0o600)
            ->addFile('postgresql.auto.conf', "restore_command = 'x'\n", 0o600)
            ->toString();

        $entries = $this->parse($tar);

        self::assertSame(['recovery.signal', 'postgresql.auto.conf'], array_column($entries, 'name'));
        self::assertSame(0, $entries[0]['size']);
        self::assertSame(22, $entries[1]['size']);
        self::assertSame(0o600, $entries[0]['mode']);

        // uid 70 is the postgres user in the Alpine images. Docker preserves what the tar says, and
        // PostgreSQL refuses to start over a data directory it does not own — so a writer that
        // silently emitted 0:0 would produce a cluster that cannot boot.
        self::assertSame(TarStream::POSTGRES_UID, $entries[0]['uid']);
        self::assertSame(TarStream::POSTGRES_UID, $entries[0]['gid']);
    }

    public function testEveryHeaderChecksumValidates(): void
    {
        $tar = (new TarStream())
            ->addDirectory('vortos/wal', 0o700)
            ->addFile('vortos/fetch-wal.sh', "#!/bin/sh\nexit 0\n", 0o755)
            ->toString();

        foreach ($this->parse($tar) as $entry) {
            self::assertTrue($entry['checksum_ok'], "bad checksum on {$entry['name']}");
        }
    }

    public function testDirectoryEntriesAreTypeFive(): void
    {
        $entries = $this->parse((new TarStream())->addDirectory('vortos/absent', 0o700)->toString());

        self::assertSame('5', $entries[0]['type']);
        // Trailing slash is what makes an extractor treat the entry as a directory.
        self::assertSame('vortos/absent/', $entries[0]['name']);
    }

    public function testArchiveIsTerminatedByTwoZeroBlocks(): void
    {
        $tar = (new TarStream())->addFile('a', 'b')->toString();

        self::assertSame(str_repeat("\0", 1024), substr($tar, -1024));
    }

    /**
     * The streamed path: a 16 MiB WAL segment is uploaded straight from disk, so the header declares
     * a size whose bytes the writer never sees. It must produce a byte-identical header to the
     * buffered path, or segments arrive corrupt only in production.
     */
    public function testStreamedHeaderMatchesTheBufferedOne(): void
    {
        $content = str_repeat('W', 1234);

        $buffered = (new TarStream())->addFile('000000010000000000000009', $content, 0o600)->toString();
        $streamed = (new TarStream())->fileHeader('000000010000000000000009', 1234, 0o600)
            . $content
            . TarStream::padding(1234)
            . TarStream::trailer();

        // mtime is the only field that can differ between two writes a second apart.
        self::assertSame($this->withoutMtime($buffered), $this->withoutMtime($streamed));
    }

    public function testRejectsAPathItCannotRepresent(): void
    {
        $this->expectExceptionMessageMatches('/exceeds 100 bytes/');

        (new TarStream())->addFile(str_repeat('a', 101), '');
    }

    /**
     * @return list<array{name: string, size: int, mode: int, uid: int, gid: int, type: string, checksum_ok: bool}>
     */
    private function parse(string $tar): array
    {
        $entries = [];

        for ($o = 0; $o + 512 <= \strlen($tar); $o += 512) {
            $block = substr($tar, $o, 512);
            if (trim($block, "\0") === '') {
                break;
            }

            $stored = (int) octdec(trim(substr($block, 148, 8), " \0"));
            $zeroed = substr_replace($block, str_repeat(' ', 8), 148, 8);
            $sum = 0;
            for ($i = 0; $i < 512; $i++) {
                $sum += \ord($zeroed[$i]);
            }

            $size = (int) octdec(trim(substr($block, 124, 12), " \0"));

            $entries[] = [
                'name' => rtrim(substr($block, 0, 100), "\0"),
                'size' => $size,
                'mode' => (int) octdec(trim(substr($block, 100, 8), " \0")),
                'uid' => (int) octdec(trim(substr($block, 108, 8), " \0")),
                'gid' => (int) octdec(trim(substr($block, 116, 8), " \0")),
                'type' => substr($block, 156, 1),
                'checksum_ok' => $sum === $stored,
            ];

            $o += 512 * (int) ceil($size / 512);
        }

        return $entries;
    }

    private function withoutMtime(string $tar): string
    {
        $out = '';
        for ($o = 0; $o + 512 <= \strlen($tar); $o += 512) {
            $out .= substr_replace(substr($tar, $o, 512), str_repeat("\0", 12), 136, 12);
        }

        return $out;
    }
}
