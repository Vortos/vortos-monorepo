<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * Test/dev export sink that keeps objects in memory. NOT for production — an export written
 * here is not durable and the returned URL is a synthetic `memory://` string.
 */
final class InMemoryExportSink implements AuditExportSinkInterface
{
    /** @var array<string, string> objectKey => body */
    public array $objects = [];

    public function put(string $key, mixed $body, string $contentType, string $downloadFilename): void
    {
        $this->objects[$key] = \is_resource($body) ? (stream_get_contents($body) ?: '') : (string) $body;
    }

    public function temporaryDownloadUrl(string $key, \DateTimeImmutable $expiresAt): string
    {
        return 'memory://' . $key;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
