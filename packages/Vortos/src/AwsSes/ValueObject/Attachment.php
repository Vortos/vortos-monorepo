<?php

declare(strict_types=1);

namespace Vortos\AwsSes\ValueObject;

final class Attachment
{
    private function __construct(
        private readonly string $filename,
        private readonly string $mimeType,
        private readonly string $content,
        private readonly bool $inline,
        private readonly ?string $contentId,
    ) {}

    /**
     * Create an attachment from raw binary content.
     */
    public static function fromContent(
        string $filename,
        string $mimeType,
        string $content,
        bool $inline = false,
        ?string $contentId = null,
    ): self {
        return new self($filename, $mimeType, base64_encode($content), $inline, $contentId);
    }

    /**
     * Create an attachment from a file path.
     */
    public static function fromPath(
        string $filePath,
        ?string $filename = null,
        ?string $mimeType = null,
        bool $inline = false,
        ?string $contentId = null,
    ): self {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException(sprintf('Attachment file not found: %s', $filePath));
        }

        $content  = file_get_contents($filePath);
        $filename = $filename ?? basename($filePath);
        $mimeType = $mimeType ?? (mime_content_type($filePath) ?: 'application/octet-stream');

        return self::fromContent($filename, $mimeType, $content, $inline, $contentId);
    }

    /**
     * Create an inline image. Content-ID is used to embed via <img src="cid:..."> in HTML body.
     */
    public static function inline(string $filename, string $mimeType, string $content, string $contentId): self
    {
        return self::fromContent($filename, $mimeType, $content, true, $contentId);
    }

    /**
     * Reconstruct from already-base64-encoded content (e.g. from outbox deserialization).
     * Does NOT apply another base64 encode pass.
     */
    public static function fromEncoded(
        string $filename,
        string $mimeType,
        string $base64Content,
        bool $inline = false,
        ?string $contentId = null,
    ): self {
        return new self($filename, $mimeType, $base64Content, $inline, $contentId);
    }

    public function filename(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    /** Base64-encoded content ready for SES SendRawEmail */
    public function content(): string
    {
        return $this->content;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function contentId(): ?string
    {
        return $this->contentId;
    }

    public function toArray(): array
    {
        return [
            'filename'   => $this->filename,
            'mime_type'  => $this->mimeType,
            'content'    => $this->content,
            'inline'     => $this->inline,
            'content_id' => $this->contentId,
        ];
    }
}
