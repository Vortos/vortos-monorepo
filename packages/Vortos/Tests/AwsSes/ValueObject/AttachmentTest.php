<?php

declare(strict_types=1);

namespace Vortos\Tests\AwsSes\ValueObject;

use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\ValueObject\Attachment;

final class AttachmentTest extends TestCase
{
    public function test_from_content_base64_encodes(): void
    {
        $content    = 'raw binary content';
        $attachment = Attachment::fromContent('file.txt', 'text/plain', $content);

        $this->assertSame(base64_encode($content), $attachment->content());
    }

    public function test_filename_and_mime_type(): void
    {
        $attachment = Attachment::fromContent('invoice.pdf', 'application/pdf', 'data');

        $this->assertSame('invoice.pdf', $attachment->filename());
        $this->assertSame('application/pdf', $attachment->mimeType());
    }

    public function test_not_inline_by_default(): void
    {
        $attachment = Attachment::fromContent('file.pdf', 'application/pdf', 'data');
        $this->assertFalse($attachment->isInline());
        $this->assertNull($attachment->contentId());
    }

    public function test_inline_attachment(): void
    {
        $attachment = Attachment::inline('logo.png', 'image/png', 'data', 'logo@cid');

        $this->assertTrue($attachment->isInline());
        $this->assertSame('logo@cid', $attachment->contentId());
    }

    public function test_from_content_inline_flag(): void
    {
        $attachment = Attachment::fromContent('file.pdf', 'application/pdf', 'data', true, 'cid123');

        $this->assertTrue($attachment->isInline());
        $this->assertSame('cid123', $attachment->contentId());
    }

    public function test_to_array_contains_all_fields(): void
    {
        $attachment = Attachment::fromContent('file.pdf', 'application/pdf', 'data', true, 'cid');
        $array      = $attachment->toArray();

        $this->assertArrayHasKey('filename', $array);
        $this->assertArrayHasKey('mime_type', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('inline', $array);
        $this->assertArrayHasKey('content_id', $array);
        $this->assertSame('file.pdf', $array['filename']);
        $this->assertTrue($array['inline']);
    }

    public function test_from_path_throws_when_file_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Attachment::fromPath('/nonexistent/file.pdf');
    }

    public function test_from_path_uses_basename_as_default_filename(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ses_attachment_');
        file_put_contents($tmpFile, 'test content');

        try {
            $attachment = Attachment::fromPath($tmpFile);
            $this->assertSame(basename($tmpFile), $attachment->filename());
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_from_path_custom_filename(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ses_attachment_');
        file_put_contents($tmpFile, 'test content');

        try {
            $attachment = Attachment::fromPath($tmpFile, 'custom-name.txt');
            $this->assertSame('custom-name.txt', $attachment->filename());
        } finally {
            unlink($tmpFile);
        }
    }
}
