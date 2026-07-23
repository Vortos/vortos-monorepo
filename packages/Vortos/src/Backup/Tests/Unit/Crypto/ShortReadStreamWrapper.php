<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Crypto;

/**
 * A stream that returns fewer bytes than asked for — the behaviour every real pipe has and
 * php://temp never does.
 *
 * The backup pipeline reads its plaintext from a pg_dump pipe and its ciphertext from an
 * object-store download; both short-read routinely. Because every crypto test used php://temp, a
 * framing bug that made EVERY encrypted production backup permanently undecryptable passed the whole
 * suite. Any test asserting stream-chunking behaviour must use this wrapper, not a temp stream.
 */
final class ShortReadStreamWrapper
{
    public const SCHEME = 'vortos-shortread';

    /** Maximum bytes any single read will return, however many are requested. */
    public const MAX_READ = 1000;

    /** @var array<string, string> registered payloads keyed by the host part of the URL */
    private static array $payloads = [];

    public mixed $context = null;

    private string $buffer = '';

    private int $position = 0;

    public static function register(): void
    {
        if (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }
    }

    /** @return string the URL to fopen() */
    public static function urlFor(string $payload): string
    {
        self::register();
        $id = 'p' . bin2hex(random_bytes(8));
        self::$payloads[$id] = $payload;

        return self::SCHEME . '://' . $id;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $id = parse_url($path, PHP_URL_HOST);
        if (!is_string($id) || !isset(self::$payloads[$id])) {
            return false;
        }

        $this->buffer = self::$payloads[$id];
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $take = min(self::MAX_READ, $count, strlen($this->buffer) - $this->position);
        if ($take <= 0) {
            return '';
        }

        $data = substr($this->buffer, $this->position, $take);
        $this->position += $take;

        return $data;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
