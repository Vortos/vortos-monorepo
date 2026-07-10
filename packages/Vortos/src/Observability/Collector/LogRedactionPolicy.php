<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use Vortos\Observability\Sink\MessageScrubber;

/**
 * The collector-side log redaction policy (Block 16, §3.3): a defence-in-depth scrub
 * applied to every log record *before it leaves the host*, on top of whatever the
 * application logger already redacts in-process.
 *
 * Rendered as a single OTel Collector `transform` processor (the only redaction-capable
 * processor that supports the *logs* signal on the pinned collector — the dedicated
 * `redaction` processor is traces/metrics-only there). Two controls:
 *  - {@see $blockedKeyPatterns} — regexes matched against attribute **key names**; a
 *    matching attribute is dropped (`delete_matching_keys`), e.g. an `authorization`
 *    or `password` field the app forgot to strip;
 *  - {@see $blockedValuePatterns} — regexes matched against the record **body** and
 *    attribute **values**; a match is masked in place (`replace_pattern` /
 *    `replace_all_patterns`) — JWTs, bearer tokens, card numbers, emails.
 *
 * Structured fields are otherwise preserved: redaction removes only what is dangerous.
 */
final readonly class LogRedactionPolicy
{
    /** The replacement token substituted for any matched secret. */
    private const MASK = '***REDACTED***';

    /**
     * @param list<string> $blockedValuePatterns PCRE regexes (single-backslash) matched against body + attribute values → masked
     * @param list<string> $blockedKeyPatterns   PCRE regexes (single-backslash) matched against attribute key names → dropped
     */
    public function __construct(
        public array $blockedValuePatterns = self::DEFAULT_BLOCKED_VALUE_PATTERNS,
        public array $blockedKeyPatterns = self::DEFAULT_BLOCKED_KEY_PATTERNS,
    ) {
    }

    /**
     * Secret/PII value shapes masked wherever they appear in a log body or attribute value.
     * Shared intent with {@see MessageScrubber} so the same rules apply to app-emitted
     * error context and to centralized container logs.
     */
    public const DEFAULT_BLOCKED_VALUE_PATTERNS = [
        '\\beyJ[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\.[A-Za-z0-9_-]+\\b',
        '(?:Bearer|Basic)\\s+[A-Za-z0-9._~+/=-]{8,}',
        '\\bAKIA[0-9A-Z]{16}\\b',
        '\\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}\\b',
        '\\b(?:\\d[ -]?){13,19}\\b',
    ];

    /**
     * Attribute key names that must never leave the host, matched case-insensitively.
     * Any attribute whose key matches is dropped — belt-and-suspenders for a field the
     * app failed to strip.
     */
    public const DEFAULT_BLOCKED_KEY_PATTERNS = [
        '(?i).*(?:password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|authorization|credential|cookie|session[_-]?id).*',
    ];

    /**
     * Renders the OTel Collector `transform` processor config that redacts log records.
     * Structure is pinned by a contract test — keep it stable.
     *
     * @return array<string, mixed>
     */
    public function toProcessorConfig(): array
    {
        $keyPatterns = $this->blockedKeyPatterns;
        sort($keyPatterns);
        $valuePatterns = $this->blockedValuePatterns;
        sort($valuePatterns);

        $statements = [];
        // 1. Drop secret-NAMED attributes outright.
        foreach ($keyPatterns as $pattern) {
            $statements[] = sprintf('delete_matching_keys(attributes, "%s")', $this->ottl($pattern));
        }
        // 2. Mask secret-SHAPED values in the body and in any attribute value.
        foreach ($valuePatterns as $pattern) {
            $ottl = $this->ottl($pattern);
            $statements[] = sprintf('replace_pattern(body, "%s", "%s")', $ottl, self::MASK);
            $statements[] = sprintf('replace_all_patterns(attributes, "value", "%s", "%s")', $ottl, self::MASK);
        }

        return [
            // error_mode: ignore — a statement that can't apply to a given record (e.g. a
            // non-string body) is skipped, never fatal to the pipeline.
            'error_mode' => 'ignore',
            'log_statements' => [
                [
                    'context' => 'log',
                    'statements' => $statements,
                ],
            ],
        ];
    }

    /**
     * Escapes a PCRE pattern for embedding in an OTTL double-quoted string literal: OTTL
     * needs each backslash doubled (`\d` → `\\d`) and any literal quote escaped. The
     * YamlWriter's own double-quote escaping then round-trips this verbatim to the collector.
     */
    private function ottl(string $pattern): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $pattern);
    }

    public static function fromScrubber(MessageScrubber $scrubber): self
    {
        // MessageScrubber's patterns are PCRE with delimiters; the collector takes bare
        // regexes, so we reuse the same intent via this policy's own default deny list
        // rather than parsing the scrubber's delimited form.
        unset($scrubber);

        return new self();
    }
}
