<?php

declare(strict_types=1);

namespace Vortos\Audit\Export\ObjectStore;

use Vortos\Audit\Export\AuditExportSinkInterface;
use Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface;
use Vortos\ObjectStore\ValueObject\ContentType;
use Vortos\ObjectStore\ValueObject\PutObjectOptions;

/**
 * Writes export objects to a Vortos ObjectStore bucket (S3 / R2 / OCI) and mints presigned
 * download URLs for them. Mirrors {@see \Vortos\Audit\Retention\ObjectStore\ObjectStoreArchiveWriter}.
 *
 * Uses {@see ImmediateObjectStoreInterface} (not the transactional store): the export runs on
 * a consumer worker with no active business DB transaction, so an outbox-backed put() would
 * fail — this path wants a direct provider write. The same immediate store also generates the
 * presigned URL ({@see ImmediateObjectStoreInterface} extends the presign contract), so a
 * single dependency covers both persist and download-URL minting.
 */
final class ObjectStoreExportSink implements AuditExportSinkInterface
{
    public function __construct(
        private readonly ImmediateObjectStoreInterface $objectStore,
    ) {}

    public function put(string $key, mixed $body, string $contentType, string $downloadFilename): void
    {
        $this->objectStore->put($key, $body, new PutObjectOptions(
            contentType: new ContentType($contentType),
            // Serve as an attachment with a stable filename when fetched via the signed URL.
            contentDisposition: sprintf('attachment; filename="%s"', $this->sanitizeFilename($downloadFilename)),
        ));
    }

    public function temporaryDownloadUrl(string $key, \DateTimeImmutable $expiresAt): string
    {
        return $this->objectStore->temporaryDownloadUrl($key, $expiresAt)->url();
    }

    public function delete(string $key): void
    {
        $this->objectStore->delete($key);
    }

    /** Strip anything that could break the Content-Disposition header. */
    private function sanitizeFilename(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'audit-export';
    }
}
