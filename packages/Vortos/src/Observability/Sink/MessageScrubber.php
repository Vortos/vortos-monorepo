<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

/**
 * Redacts personally-identifiable / secret material from free text **before** it
 * leaves the process toward an error backend.
 *
 * This is the by-construction leak guard for {@see CapturedError}: a stack-trace
 * message or context value can carry an email, a bearer token, a JWT, a card number
 * or a long digit run, and the most common real-world leak is shipping that verbatim
 * to a third-party error tracker. Each pattern lives here (not buried inline) so the
 * set is reviewable and tested as a table.
 *
 * Patterns are intentionally conservative — false positives (over-redaction) are
 * acceptable; false negatives (leaks) are not.
 */
final class MessageScrubber
{
    private const REDACTED = '[redacted]';

    /**
     * Ordered most-specific → most-general so a JWT isn't first eaten by the generic
     * long-token rule. Each entry is a PCRE the scrubber replaces with [redacted].
     *
     * @var list<non-empty-string>
     */
    private const PATTERNS = [
        // JWT: three base64url segments separated by dots.
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/',
        // Authorization: Bearer / Basic <token>.
        '/\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i',
        // key=value / key: value secrets (token, secret, password, api_key, dsn, authorization).
        '/\b(?:authorization|password|passwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|client[_-]?secret|dsn)\b\s*[=:]\s*\S+/i',
        // Email addresses.
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
        // Credit-card-ish: 13-19 digit runs, optionally separated by spaces/dashes.
        '/\b(?:\d[ -]?){13,19}\b/',
        // Generic long opaque tokens (>= 24 of base64/hex-ish chars).
        '/\b[A-Za-z0-9_-]{24,}\b/',
    ];

    public function scrub(string $value): string
    {
        foreach (self::PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTED, $value);
            if ($replaced !== null) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * Scrub a context map: keys whose name signals a secret are fully redacted;
     * scalar values are scrubbed; non-scalars are summarized to their type so a
     * nested object can't smuggle PII through a __toString.
     *
     * @param array<array-key, mixed> $context
     * @return array<string, scalar>
     */
    public function scrubContext(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $name = is_string($key) ? $key : (string) $key;
            if ($name === '') {
                continue;
            }

            if (preg_match('/authorization|password|passwd|secret|token|api[_-]?key|private[_-]?key|client[_-]?secret|dsn|cookie/i', $name) === 1) {
                $out[$name] = self::REDACTED;
                continue;
            }

            if (is_scalar($value)) {
                $out[$name] = is_string($value) ? $this->scrub($value) : $value;
                continue;
            }

            $out[$name] = '<' . get_debug_type($value) . '>';
        }

        return $out;
    }
}
