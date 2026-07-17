<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * Durable destination for a generated export (the NDJSON body and its signed manifest),
 * plus the ability to mint a time-limited download URL for a stored object.
 *
 * Kept as a narrow port — exactly like {@see \Vortos\Audit\Retention\AuditArchiveWriterInterface}
 * — so vortos-audit never hard-depends on vortos-object-store: production wires the
 * {@see ObjectStore\ObjectStoreExportSink}, tests wire {@see InMemoryExportSink}. An export
 * is NEVER served inline from the request; it always lands here and is fetched out-of-band
 * via the signed URL, which is what keeps a multi-million-record export off the app's heap
 * and out of the HTTP response.
 */
interface AuditExportSinkInterface
{
    /**
     * Persist one export object. The body is streamed, not buffered: a resource is written
     * through to the provider so memory stays bounded regardless of export size.
     *
     * @param resource|string $body            Stream resource (preferred) or in-memory string.
     * @param string          $contentType     e.g. 'application/x-ndjson'.
     * @param string          $downloadFilename Suggested filename for a browser download.
     */
    public function put(string $key, mixed $body, string $contentType, string $downloadFilename): void;

    /**
     * A time-limited download URL for a previously {@see put()} object. The caller fetches
     * the export directly from storage with this URL; it expires at $expiresAt.
     */
    public function temporaryDownloadUrl(string $key, \DateTimeImmutable $expiresAt): string;

    /**
     * Remove a stored export object. Used by the retention GC once an artifact ages out.
     * Deleting an already-absent key is a no-op, not an error (GC must be idempotent).
     */
    public function delete(string $key): void;
}
