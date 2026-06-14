<?php

declare(strict_types=1);

namespace Vortos\Logger\Handler;

use Monolog\Handler\RotatingFileHandler;

/**
 * RotatingFileHandler that gzip-compresses each rotated-out file.
 *
 * Compression runs once per rotation (daily), not per write, so the
 * performance cost is negligible. Already-compressed (.gz) files are left
 * alone. Retention-by-count (maxFiles) is inherited from RotatingFileHandler;
 * `vortos:logs:prune` additionally enforces maxAgeDays/maxTotalSizeMb across
 * both compressed and uncompressed files.
 */
final class CompressingRotatingFileHandler extends RotatingFileHandler
{
    protected function rotate(): void
    {
        $previousUrl = $this->url;

        parent::rotate();

        if ($previousUrl !== $this->url && is_file($previousUrl) && !str_ends_with($previousUrl, '.gz')) {
            $this->compress($previousUrl);
        }
    }

    private function compress(string $path): void
    {
        $gzPath = $path . '.gz';

        $source = @fopen($path, 'rb');
        if ($source === false) {
            return;
        }

        $destination = @gzopen($gzPath, 'wb9');
        if ($destination === false) {
            fclose($source);
            return;
        }

        while (!feof($source)) {
            gzwrite($destination, fread($source, 1024 * 512));
        }

        fclose($source);
        gzclose($destination);

        unlink($path);
    }
}
