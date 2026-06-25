<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

use php_user_filter;

/**
 * A PHP stream read-filter that feeds every byte read from a stream into a
 * {@see HashStreamSink} as it passes through — letting the store compute the artifact
 * checksum and size in the *same single read* it uploads, with bounded memory.
 *
 * Usage:
 *   $sink = new HashStreamSink();
 *   ChecksumStreamFilter::attach($sourceResource, $sink);
 *   // ... consumer reads $sourceResource to EOF (e.g. the object store upload) ...
 *   $sink->finalize();
 *   $checksum = $sink->checksum(); $size = $sink->bytes();
 */
final class ChecksumStreamFilter extends php_user_filter
{
    public const FILTER_NAME = 'vortos.backup.checksum';

    private static bool $registered = false;

    /** @param resource $stream */
    public static function attach($stream, HashStreamSink $sink): void
    {
        self::register();
        stream_filter_append($stream, self::FILTER_NAME, STREAM_FILTER_READ, $sink);
    }

    public static function register(): void
    {
        if (!self::$registered) {
            stream_filter_register(self::FILTER_NAME, self::class);
            self::$registered = true;
        }
    }

    /**
     * @param resource $in
     * @param resource $out
     * @param int      $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            if ($this->params instanceof HashStreamSink) {
                $this->params->update($bucket->data);
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }
}
