<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

/**
 * Per-read options for fetching an object or presigning a download URL.
 *
 * `$range` applies to a direct read. The other two apply only when presigning:
 * S3-compatible stores let a signed GET override the response's Content-Disposition
 * and Content-Type, and because those overrides are part of the signature a caller
 * cannot strip or alter them by editing the URL.
 *
 * That is what makes them a security control rather than a nicety. Presigning a
 * third-party upload without a disposition serves it with whatever Content-Type
 * was recorded at upload — so a bucket reachable by public upload can be made to
 * serve `text/html` that executes on the storage origin. `forceDownload()` closes
 * that, and is the right default for any object the application did not author.
 */
final class GetObjectOptions
{
    public function __construct(
        private readonly ?ByteRange $range = null,
        private readonly ?ContentDisposition $disposition = null,
        private readonly ?string $responseContentType = null,
    ) {}

    /**
     * Serve as a download, never rendered inline, with an optional filename.
     *
     * Also pins the response Content-Type to `application/octet-stream` so a
     * browser that ignores the disposition still has nothing to render and no
     * sniffing to do. Use this for anything a third party uploaded.
     */
    public static function forceDownload(?string $filename = null): self
    {
        return new self(
            disposition:         ContentDisposition::attachment($filename),
            responseContentType: 'application/octet-stream',
        );
    }

    /**
     * Render in the browser under a Content-Type the application chooses.
     *
     * Only for bytes the application itself produced — a generated invoice, an
     * export it rendered. Passing a caller-supplied content type here reopens
     * exactly what `forceDownload()` exists to close.
     */
    public static function renderInline(string $contentType, ?string $filename = null): self
    {
        return new self(
            disposition:         ContentDisposition::inline($filename),
            responseContentType: $contentType,
        );
    }

    public function range(): ?ByteRange
    {
        return $this->range;
    }

    public function disposition(): ?ContentDisposition
    {
        return $this->disposition;
    }

    /** The rendered Content-Disposition, or null when the caller set none. */
    public function responseContentDisposition(): ?string
    {
        return $this->disposition?->headerValue();
    }

    public function responseContentType(): ?string
    {
        return $this->responseContentType;
    }

    /** Same options with a byte range applied — the read-side concern kept separate. */
    public function withRange(ByteRange $range): self
    {
        return new self($range, $this->disposition, $this->responseContentType);
    }
}
