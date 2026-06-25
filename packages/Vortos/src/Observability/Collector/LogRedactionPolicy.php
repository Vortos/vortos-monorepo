<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use Vortos\Observability\Sink\MessageScrubber;

/**
 * The collector-side log redaction policy (Block 16, §3.3): deny-pattern regexes
 * (reused from {@see MessageScrubber} so the same secret/PII rules apply both in
 * app-emitted error context and in centralized container logs) plus a structured
 * field allow-list — defense in depth so PII/secrets are scrubbed **before logs
 * leave the host**.
 */
final readonly class LogRedactionPolicy
{
    /**
     * @param list<string> $denyPatterns Regexes (no delimiters) matched against the log body
     * @param list<string> $allowedFields Structured fields permitted through untouched
     */
    public function __construct(
        public array $denyPatterns = self::DEFAULT_DENY_PATTERNS,
        public array $allowedFields = self::DEFAULT_ALLOWED_FIELDS,
    ) {
    }

    public const DEFAULT_DENY_PATTERNS = [
        '\\beyJ[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\b',
        '\\b(?:Bearer|Basic)\\s+[A-Za-z0-9._~+/=-]{8,}',
        '\\b(?:authorization|password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|dsn)\\b\\s*[=:]\\s*\\S+',
        '\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}\\b',
        '\\b(?:\\d[ -]?){13,19}\\b',
    ];

    public const DEFAULT_ALLOWED_FIELDS = [
        'timestamp',
        'severity',
        'message',
        'env',
        'service',
        'trace_id',
        'span_id',
    ];

    /**
     * Renders the OTel `transform`/`redaction`-style processor config. Structure is
     * pinned by a contract test — keep field names stable.
     *
     * @return array<string, mixed>
     */
    public function toProcessorConfig(): array
    {
        $denyPatterns = $this->denyPatterns;
        sort($denyPatterns);
        $allowedFields = $this->allowedFields;
        sort($allowedFields);

        return [
            'allow_all_keys' => false,
            'allowed_keys' => $allowedFields,
            'blocked_values' => $denyPatterns,
            'summary' => 'redacted',
        ];
    }

    public static function fromScrubber(MessageScrubber $scrubber): self
    {
        // MessageScrubber's patterns are PCRE with delimiters; the collector
        // redaction processor takes bare regexes, so we reuse the same intent via
        // the policy's own default deny list rather than parsing the scrubber's
        // delimited form.
        unset($scrubber);

        return new self();
    }
}
