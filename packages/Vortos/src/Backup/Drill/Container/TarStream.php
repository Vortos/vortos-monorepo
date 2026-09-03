<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill\Container;

use InvalidArgumentException;

/**
 * Builds a minimal USTAR archive in memory — the wire format Docker's
 * `PUT /containers/{id}/archive` endpoint expects.
 *
 * WHY A HAND-ROLLED WRITER rather than PharData or shelling out to `tar`. The PITR drill pushes
 * small control files into a container that is not running yet: the recovery configuration, the
 * `restore_command` script, and — one at a time, during recovery — a 16 MiB WAL segment. PharData
 * cannot write to a stream and refuses to operate when `phar.readonly` is on (it is, in production);
 * shelling out to `tar` means a temp directory, the plaintext of a WAL segment on disk, and a
 * dependency on a binary the drill has no other reason to need. Emitting the handful of header
 * fields Docker actually reads is smaller than either.
 *
 * Deliberately NOT used for the base backup itself. That artifact is ALREADY a tar — `pg_basebackup
 * --format=tar` — and it is streamed through to Docker byte-for-byte without ever being parsed or
 * rebuilt. Nothing here touches it, which is what keeps the base restore bounded in memory and
 * removes any chance of this writer corrupting the one artifact that matters.
 *
 * UID/GID ARE LOAD-BEARING and default to 70 (the `postgres` user in the Alpine images this drill
 * uses on both sides). Docker preserves the ownership recorded in the tar, and PostgreSQL refuses
 * to start when its data directory is owned by anyone but the server user — so a writer that
 * emitted the usual 0:0 would produce a cluster that cannot boot, with an error pointing at
 * permissions rather than at this file.
 */
final class TarStream
{
    public const BLOCK = 512;

    /** The `postgres` user in the official Alpine PostgreSQL images, on both sides of this drill. */
    public const POSTGRES_UID = 70;

    /** @var list<string> */
    private array $blocks = [];

    /**
     * Add one regular file.
     *
     * @param string $path relative path inside the archive, e.g. `recovery.signal`
     * @param int    $mode octal permission bits, e.g. 0o600
     */
    public function addFile(
        string $path,
        string $contents,
        int $mode = 0o600,
        int $uid = self::POSTGRES_UID,
        int $gid = self::POSTGRES_UID,
        ?int $mtime = null,
    ): self {
        $this->blocks[] = $this->header($path, \strlen($contents), $mode, $uid, $gid, $mtime, '0');
        $this->blocks[] = $this->pad($contents);

        return $this;
    }

    /**
     * Add a directory entry.
     *
     * Docker creates missing parents on extract, so this is only needed when the directory's own
     * mode or ownership matters — which for a PostgreSQL data directory it very much does.
     */
    public function addDirectory(
        string $path,
        int $mode = 0o700,
        int $uid = self::POSTGRES_UID,
        int $gid = self::POSTGRES_UID,
        ?int $mtime = null,
    ): self {
        $this->blocks[] = $this->header(rtrim($path, '/') . '/', 0, $mode, $uid, $gid, $mtime, '5');

        return $this;
    }

    /**
     * The finished archive, including the two zero blocks that terminate it.
     *
     * The trailer is not decoration: without it Docker's extractor treats the stream as truncated
     * and rejects the upload, which surfaces as an opaque 500 rather than as "your tar is short".
     */
    public function toString(): string
    {
        return implode('', $this->blocks) . str_repeat("\0", self::BLOCK * 2);
    }

    /**
     * A single file header block, for content that will be streamed rather than held in memory.
     *
     * A 16 MiB WAL segment is uploaded straight from disk, so the writer needs to emit a header
     * declaring a size it never sees the bytes of. Exposed here rather than reimplemented by the
     * caller so the checksum rules — which are easy to get subtly wrong and fail opaquely — exist
     * in exactly one place. Follow it with the content, pad to {@see BLOCK}, and finish with two
     * zero blocks.
     */
    public function fileHeader(
        string $path,
        int $size,
        int $mode = 0o600,
        int $uid = self::POSTGRES_UID,
        int $gid = self::POSTGRES_UID,
    ): string {
        return $this->header($path, $size, $mode, $uid, $gid, null, '0');
    }

    /** The two zero blocks that terminate an archive. */
    public static function trailer(): string
    {
        return str_repeat("\0", self::BLOCK * 2);
    }

    /** Zero padding that rounds $size up to a whole number of blocks. */
    public static function padding(int $size): string
    {
        $remainder = $size % self::BLOCK;

        return $remainder === 0 ? '' : str_repeat("\0", self::BLOCK - $remainder);
    }

    private function pad(string $contents): string
    {
        $remainder = \strlen($contents) % self::BLOCK;

        return $contents . ($remainder === 0 ? '' : str_repeat("\0", self::BLOCK - $remainder));
    }

    private function header(
        string $path,
        int $size,
        int $mode,
        int $uid,
        int $gid,
        ?int $mtime,
        string $typeflag,
    ): string {
        $path = ltrim($path, '/');
        if ($path === '') {
            throw new InvalidArgumentException('Tar entry path must not be empty.');
        }
        // The USTAR name field is 100 bytes and this writer emits no prefix/longname extension.
        // Every path it is asked to carry is a short control file or a 24-character WAL segment
        // name, so the limit is unreachable in practice — but it is checked rather than silently
        // truncated, because a truncated name inside a data directory is a file PostgreSQL will not
        // find and cannot explain.
        if (\strlen($path) > 100) {
            throw new InvalidArgumentException(sprintf('Tar entry path exceeds 100 bytes: %s', $path));
        }

        $header = pack('a100', $path)
            . pack('a8', sprintf('%07o', $mode & 0o7777))
            . pack('a8', sprintf('%07o', $uid))
            . pack('a8', sprintf('%07o', $gid))
            . pack('a12', sprintf('%011o', $size))
            . pack('a12', sprintf('%011o', $mtime ?? time()))
            // Checksum field: spaces while the checksum is computed over the header, per the format.
            . str_repeat(' ', 8)
            . pack('a1', $typeflag)
            . pack('a100', '')            // linkname
            . pack('a6', 'ustar')
            . pack('a2', '00')
            . pack('a32', 'postgres')     // uname
            . pack('a32', 'postgres')     // gname
            . pack('a8', '')              // devmajor
            . pack('a8', '')              // devminor
            . pack('a155', '')            // prefix
            . str_repeat("\0", 12);       // padding to 512

        $checksum = 0;
        for ($i = 0, $n = \strlen($header); $i < $n; $i++) {
            $checksum += \ord($header[$i]);
        }

        // The checksum is written as six octal digits, a NUL, then a space — the layout GNU tar and
        // Go's archive/tar (which is what Docker uses) both expect. Writing seven octal digits here
        // is the classic off-by-one that makes every entry fail verification.
        return substr_replace(
            $header,
            pack('a8', sprintf('%06o', $checksum) . "\0 "),
            148,
            8,
        );
    }
}
