<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Support;

/**
 * A test stream wrapper that behaves like a live `pg_dump` pipe: non-seekable, unknown size, and
 * — crucially — delivering at most a small `chunk` per `fread`, so a reader that does not accumulate
 * would build undersized multipart parts. Used to prove the object store reads forward-only and
 * fills each part before uploading.
 *
 * Usage:
 *   ChunkedNonSeekableStream::register();
 *   $resource = ChunkedNonSeekableStream::open($bytes, chunkSize: 4096);
 */
final class ChunkedNonSeekableStream
{
    /** @var array<string, array{0: string, 1: int}> */
    private static array $registry = [];

    private static bool $registered = false;

    /** @var resource */
    public $context;

    private string $data = '';
    private int $chunk = 8192;
    private int $pos = 0;

    public static function register(): void
    {
        if (!self::$registered) {
            stream_wrapper_register('chunked-nonseek', self::class);
            self::$registered = true;
        }
    }

    /** @return resource */
    public static function open(string $data, int $chunkSize)
    {
        self::register();
        $id = bin2hex(random_bytes(8));
        self::$registry[$id] = [$data, $chunkSize];

        $handle = fopen('chunked-nonseek://' . $id, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open chunked non-seekable test stream.');
        }

        return $handle;
    }

    public function stream_open(string $path): bool
    {
        $id = substr($path, \strlen('chunked-nonseek://'));
        if (!isset(self::$registry[$id])) {
            return false;
        }

        [$this->data, $this->chunk] = self::$registry[$id];
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $remaining = \strlen($this->data) - $this->pos;
        if ($remaining <= 0) {
            return '';
        }

        $n = min($count, $this->chunk, $remaining);
        $out = substr($this->data, $this->pos, $n);
        $this->pos += $n;

        return $out;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= \strlen($this->data);
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return $this->pos;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        // Report an unknown size, exactly like a pipe — forces size-by-tracking, never by fstat.
        return ['size' => 0];
    }
}
