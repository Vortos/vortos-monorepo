<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\ValueObject;

/**
 * A Content-Disposition header value that is safe to build from a filename the
 * application did not choose.
 *
 * Two things make this a value object rather than a sprintf at the call site.
 *
 * First, injection. The rendered string ends up in a `response-content-disposition`
 * query parameter and, from there, in a response header. A filename carrying a
 * quote, a semicolon, a CR or an LF would otherwise let an uploader append header
 * parameters — or, on a store that does not re-encode, split the header entirely.
 * Every character outside a conservative allowlist is dropped here rather than
 * escaped, because there is no legitimate filename that needs them and a dropped
 * character fails safe while a mis-escaped one does not.
 *
 * Second, non-ASCII. RFC 6266 says a bare `filename=` is ASCII-only, so a name in
 * Sinhala, Chinese or Arabic has to travel in the RFC 5987 `filename*=UTF-8''…`
 * form. Emitting both — an ASCII fallback and the encoded form — is what makes a
 * Sri Lankan athlete's uploaded passport scan download with its own name in a
 * modern browser and a sane approximation in an old one.
 */
final class ContentDisposition implements \Stringable
{
    /**
     * Characters permitted in the ASCII `filename=` fallback.
     *
     * Deliberately narrower than RFC 6266 allows: letters, digits, and the four
     * punctuation marks real filenames need. Anything else — including the quote,
     * semicolon, backslash and control characters that make header injection
     * possible — is replaced with an underscore.
     */
    private const ASCII_SAFE = '/[^A-Za-z0-9._-]/';

    /**
     * Characters removed from the name outright, in every form it is emitted.
     *
     * C0 and C1 controls (header splitting), DEL, and the four ASCII punctuation
     * marks that carry structural meaning inside a Content-Disposition value:
     * quote, semicolon, comma and backslash. Everything else — including all
     * non-ASCII letters — is a legitimate part of somebody's filename.
     */
    private const UNSAFE = '/[\x00-\x1F\x7F\x{0080}-\x{009F}";,\\\\]/u';

    /** Guards against a pathological name bloating the presigned URL. */
    private const MAX_FILENAME_CHARS = 120;

    public function __construct(
        private readonly ContentDispositionType $type,
        private readonly ?string $filename = null,
    ) {}

    /** Force a download. The right choice for anything a third party uploaded. */
    public static function attachment(?string $filename = null): self
    {
        return new self(ContentDispositionType::Attachment, $filename);
    }

    /** Render in the browser. Only for bytes the application itself produced. */
    public static function inline(?string $filename = null): self
    {
        return new self(ContentDispositionType::Inline, $filename);
    }

    public function type(): ContentDispositionType
    {
        return $this->type;
    }

    public function filename(): ?string
    {
        return $this->filename;
    }

    /**
     * The header value, e.g. `attachment; filename="passport.pdf"`.
     *
     * A filename that sanitises down to nothing — one made entirely of characters
     * the allowlist rejects — yields a bare disposition rather than an empty
     * `filename=""`, which some browsers treat as a reason to invent their own.
     */
    public function headerValue(): string
    {
        $value = $this->type->value;

        if ($this->filename === null || trim($this->filename) === '') {
            return $value;
        }

        // Strip any path the caller left on the name before anything else: a
        // stored key or an OS path is not a filename, and `../` in a download
        // name is a bad suggestion to hand a browser.
        $name = basename(str_replace('\\', '/', $this->filename));

        // Sanitise BEFORE deciding anything, and sanitise for meaning rather than
        // for encoding. Percent-encoding a control character makes it safe to put
        // in the header but does not make it a filename — a browser decoding
        // `filename*=UTF-8''a.pdf%0D%0A…` still ends up writing a name with a CRLF
        // in it. So the dangerous characters are removed from the name itself, and
        // only what survives is ever encoded or echoed.
        $clean = (string) preg_replace(self::UNSAFE, '', $name);
        $clean = trim(mb_substr($clean, 0, self::MAX_FILENAME_CHARS));

        if ($clean === '') {
            return $value;
        }

        // The ASCII fallback for clients that do not implement RFC 5987.
        $ascii = trim((string) preg_replace(self::ASCII_SAFE, '_', $clean), '_');

        if ($ascii !== '') {
            $value .= sprintf('; filename="%s"', $ascii);
        }

        // The extended form is added only when the cleaned name genuinely carries
        // non-ASCII — a Sinhala or Chinese filename — never merely because the
        // input contained something we removed.
        if ($clean !== $ascii) {
            $value .= sprintf("; filename*=UTF-8''%s", rawurlencode($clean));
        }

        return $value;
    }

    public function __toString(): string
    {
        return $this->headerValue();
    }
}
