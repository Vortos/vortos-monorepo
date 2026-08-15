<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\ValueObject;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\ValueObject\ContentDisposition;
use Vortos\ObjectStore\ValueObject\ContentDispositionType;
use Vortos\ObjectStore\ValueObject\GetObjectOptions;

final class ContentDispositionTest extends TestCase
{
    public function test_attachment_without_filename_is_the_bare_token(): void
    {
        $this->assertSame('attachment', ContentDisposition::attachment()->headerValue());
        $this->assertSame('inline', ContentDisposition::inline()->headerValue());
    }

    public function test_plain_ascii_filename_needs_no_extended_form(): void
    {
        $this->assertSame(
            'attachment; filename="passport.pdf"',
            ContentDisposition::attachment('passport.pdf')->headerValue(),
        );
    }

    /**
     * The point of the value object. Each of these, interpolated raw, would let an
     * uploader append header parameters or split the header outright.
     */
    #[DataProvider('injectionAttempts')]
    public function test_header_injection_characters_never_survive(string $filename): void
    {
        $value = ContentDisposition::attachment($filename)->headerValue();

        // Parse the header the way a client would, then assert on the parameter
        // VALUES. Asserting on the raw string would trip over the legitimate
        // quotes and semicolons that delimit the parameters.
        $this->assertMatchesRegularExpression('/^(attachment|inline)(; [a-z*]+=[^;]*)*$/', $value);

        preg_match('/filename="([^"]*)"/', $value, $ascii);
        preg_match("/filename\*=UTF-8''(\S*)/", $value, $extended);

        $decoded = ($ascii[1] ?? '') . rawurldecode($extended[1] ?? '');

        foreach (["\r", "\n", "\0", '"', ';', ',', '\\'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $decoded,
                sprintf('%s survived sanitisation in %s', json_encode($forbidden), $value),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function injectionAttempts(): iterable
    {
        yield 'quote closes the parameter'    => ['evil".pdf'];
        yield 'semicolon appends a parameter' => ['a.pdf; filename=b.exe'];
        yield 'CRLF splits the header'        => ["a.pdf\r\nX-Injected: 1"];
        yield 'bare LF'                       => ["a.pdf\nX-Injected: 1"];
        yield 'backslash escapes'             => ['a\\".pdf'];
    }

    public function test_path_segments_are_stripped_to_a_bare_name(): void
    {
        $this->assertSame(
            'attachment; filename="passport.pdf"',
            ContentDisposition::attachment('../../etc/passport.pdf')->headerValue(),
        );

        $this->assertSame(
            'attachment; filename="passport.pdf"',
            ContentDisposition::attachment('C:\\Users\\x\\passport.pdf')->headerValue(),
        );
    }

    public function test_non_ascii_filename_gets_both_forms(): void
    {
        $value = ContentDisposition::attachment('ශ්‍රී.pdf')->headerValue();

        // An ASCII fallback for old clients, and the RFC 5987 form for the rest.
        $this->assertStringContainsString('filename="', $value);
        $this->assertStringContainsString("filename*=UTF-8''", $value);
        $this->assertStringContainsString(rawurlencode('ශ්‍රී.pdf'), $value);
    }

    public function test_filename_of_only_unsafe_characters_yields_no_filename_parameter(): void
    {
        // An empty filename="" makes some browsers invent their own name; a bare
        // disposition is the honest answer.
        $this->assertSame('attachment', ContentDisposition::attachment('///')->headerValue());
    }

    public function test_absurdly_long_filename_is_truncated(): void
    {
        $value = ContentDisposition::attachment(str_repeat('a', 5000) . '.pdf')->headerValue();

        $this->assertLessThan(400, strlen($value));
    }

    public function test_force_download_pins_octet_stream(): void
    {
        $options = GetObjectOptions::forceDownload('passport.pdf');

        $this->assertSame('attachment; filename="passport.pdf"', $options->responseContentDisposition());
        $this->assertSame('application/octet-stream', $options->responseContentType());
        $this->assertSame(ContentDispositionType::Attachment, $options->disposition()?->type());
    }

    public function test_render_inline_keeps_the_application_chosen_type(): void
    {
        $options = GetObjectOptions::renderInline('application/pdf', 'invoice.pdf');

        $this->assertSame('inline; filename="invoice.pdf"', $options->responseContentDisposition());
        $this->assertSame('application/pdf', $options->responseContentType());
    }

    public function test_options_default_to_no_overrides_so_existing_callers_are_unchanged(): void
    {
        $options = new GetObjectOptions();

        $this->assertNull($options->responseContentDisposition());
        $this->assertNull($options->responseContentType());
        $this->assertNull($options->range());
    }
}
