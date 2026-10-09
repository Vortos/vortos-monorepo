<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Driver\S3;

use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\StreamInterface;
use Vortos\ObjectStore\Exception\ObjectStoreException;

/**
 * Reads a whole object as a sequence of byte-range GETs and spools it into one seekable handle.
 *
 * This is the download half of what the AWS Transfer Manager does, and why: a single GetObject puts
 * the entire object inside ONE HTTP request, so every limit on a request becomes a limit on object
 * size. A 357 MB base backup read through a client with a 5 s request timeout succeeded or failed
 * depending on how fast R2 happened to be that minute, and a failure 300 MB in threw all 300 MB away
 * and started again. Splitting the read gives each part its own request — its own timeout budget,
 * its own SDK retries — so a slow or dropped connection costs one part, never the whole object, and
 * no object is ever too large for the client it is read through.
 *
 * - The first part is fetched before anything else is known. Its Content-Range carries the total
 *   size and its ETag pins the version, so a small object costs exactly one request, as before.
 * - Every later part sends If-Match with that ETag. An object overwritten mid-read fails with 412
 *   instead of stitching two versions into one file that passes every length check.
 * - Up to `$concurrency` parts are in flight; they are appended strictly in offset order, and every
 *   part's length is checked against the range asked for. A short or long part is an error.
 * - The result is a `php://temp` handle — the same seekable resource callers already received from
 *   the SDK's buffered body — so a caller that peeks at magic bytes and rewinds keeps working.
 *   Resident memory stays bounded: the spool spills to a temporary file past 1 MiB.
 */
final class S3RangedDownloader
{
    private const SPOOL_MEMORY_BYTES = 1_048_576;
    private const COPY_CHUNK_BYTES = 262_144;

    public function __construct(
        private readonly S3Client $client,
        private readonly int $partSizeBytes,
        private readonly int $concurrency,
    ) {
        if ($partSizeBytes < 1) {
            throw new \InvalidArgumentException('Ranged download part size must be at least 1 byte.');
        }
        if ($concurrency < 1) {
            throw new \InvalidArgumentException('Ranged download concurrency must be at least 1.');
        }
    }

    /**
     * @param array<string, mixed> $request a GetObject request WITHOUT a Range
     *
     * @return resource seekable, positioned at offset 0
     *
     * @throws S3Exception from any part, for the caller to map
     */
    public function download(array $request): mixed
    {
        $spool = fopen('php://temp/maxmemory:' . self::SPOOL_MEMORY_BYTES, 'w+b');
        if ($spool === false) {
            throw new ObjectStoreException('Cannot open a spool for the object download.');
        }

        try {
            $this->fill($spool, $request);
            rewind($spool);

            return $spool;
        } catch (\Throwable $e) {
            fclose($spool);
            throw $e;
        }
    }

    /**
     * @param resource $spool
     * @param array<string, mixed> $request
     */
    private function fill(mixed $spool, array $request): void
    {
        try {
            $first = $this->client->getObject($request + ['Range' => $this->range(0, $this->partSizeBytes - 1)]);
        } catch (S3Exception $e) {
            // A range on a zero-byte object is unsatisfiable by definition. The plain GET is the
            // only way to read it, and it is empty, so it fits any client.
            if ($e->getStatusCode() === 416) {
                $this->append($spool, $this->client->getObject($request)['Body'] ?? '');

                return;
            }
            throw $e;
        }

        $total = $this->totalSize($first);

        // No Content-Range: the server ignored Range and sent the whole object, which is complete.
        if ($total === null) {
            $this->append($spool, $first['Body'] ?? '');

            return;
        }

        // A Content-Encoding object is ranged over its ENCODED bytes while the client decodes each
        // response on its own, so the parts would not concatenate into the object. Read it whole.
        if ($this->isEncoded($first)) {
            $this->discard($first);
            $this->append($spool, $this->client->getObject($request)['Body'] ?? '');

            return;
        }

        $this->appendExactly($spool, $first, 0, min($this->partSizeBytes, $total));

        $etag = isset($first['ETag']) ? (string) $first['ETag'] : null;

        /** @var array<int, PromiseInterface> $pending offset => promise, in offset order */
        $pending = [];
        $next = $this->partSizeBytes;

        try {
            while ($next < $total || $pending !== []) {
                while ($next < $total && \count($pending) < $this->concurrency) {
                    $part = $request + ['Range' => $this->range($next, min($next + $this->partSizeBytes, $total) - 1)];
                    if ($etag !== null) {
                        $part['IfMatch'] = $etag;
                    }
                    $pending[$next] = $this->client->getObjectAsync($part);
                    $next += $this->partSizeBytes;
                }

                $offset = array_key_first($pending);
                $promise = $pending[$offset];
                unset($pending[$offset]);

                // Waiting on the oldest part drives the shared curl multi handle, so the parts
                // behind it keep transferring meanwhile.
                $this->appendExactly($spool, $promise->wait(), $offset, min($this->partSizeBytes, $total - $offset));
            }
        } finally {
            foreach ($pending as $promise) {
                $promise->cancel();
            }
        }

        $size = fstat($spool)['size'] ?? null;
        if ($size !== $total) {
            throw new ObjectStoreException(sprintf(
                'Ranged download assembled %s bytes of a %d-byte object.',
                $size === null ? 'an unknown number of' : (string) $size,
                $total,
            ));
        }
    }

    /**
     * @param resource $spool
     * @param Result<string, mixed> $part
     */
    private function appendExactly(mixed $spool, Result $part, int $offset, int $expected): void
    {
        $written = $this->append($spool, $part['Body'] ?? '');

        if ($written !== $expected) {
            throw new ObjectStoreException(sprintf(
                'Ranged download received %d bytes for the part at offset %d; the range asked for %d.',
                $written,
                $offset,
                $expected,
            ));
        }
    }

    /**
     * Copies a part into the spool in bounded chunks and releases the part's own buffer.
     *
     * @param resource $spool
     */
    private function append(mixed $spool, mixed $body): int
    {
        if (\is_string($body)) {
            return $this->write($spool, $body);
        }

        if (!$body instanceof StreamInterface) {
            return $this->write($spool, (string) $body);
        }

        $written = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::COPY_CHUNK_BYTES);
                if ($chunk === '') {
                    break;
                }
                $written += $this->write($spool, $chunk);
            }
        } finally {
            $body->close();
        }

        return $written;
    }

    /** @param resource $spool */
    private function write(mixed $spool, string $bytes): int
    {
        $length = \strlen($bytes);
        $offset = 0;

        while ($offset < $length) {
            $put = fwrite($spool, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($put === false || $put === 0) {
                throw new ObjectStoreException('Short write spooling an object download.');
            }
            $offset += $put;
        }

        return $length;
    }

    /** @param Result<string, mixed> $result */
    private function discard(Result $result): void
    {
        $body = $result['Body'] ?? null;
        if ($body instanceof StreamInterface) {
            $body->close();
        }
    }

    /** @param Result<string, mixed> $result */
    private function totalSize(Result $result): ?int
    {
        $contentRange = (string) ($result['ContentRange'] ?? '');
        if ($contentRange === '') {
            return null;
        }

        if (preg_match('#^bytes \d+-\d+/(\d+)$#', trim($contentRange), $m) !== 1) {
            throw new ObjectStoreException(sprintf('Unparseable Content-Range "%s" on a ranged read.', $contentRange));
        }

        return (int) $m[1];
    }

    /** @param Result<string, mixed> $result */
    private function isEncoded(Result $result): bool
    {
        $encoding = strtolower(trim((string) ($result['ContentEncoding'] ?? '')));

        return $encoding !== '' && $encoding !== 'identity';
    }

    private function range(int $first, int $last): string
    {
        return sprintf('bytes=%d-%d', $first, $last);
    }
}
