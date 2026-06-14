<?php

declare(strict_types=1);

namespace Vortos\Logger\Processor;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redacts secrets and PII from log context before records leave the process.
 */
final class RedactionProcessor implements ProcessorInterface
{
    private const DEFAULT_REPLACEMENT = '[REDACTED]';

    private const DEFAULT_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'email',
        'phone',
        'ssn',
        'cookie',
        'set_cookie',
    ];

    /**
     * Regex patterns matched against string *values* (not keys) and the log
     * message itself — catches secrets/PII embedded in free-form text that a
     * key-based check would miss (e.g. a JWT pasted into an error message).
     */
    private const VALUE_PATTERNS = [
        // JWT: header.payload.signature, each base64url
        '/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/' => '[REDACTED_JWT]',
        // AWS access key IDs
        '/\b(AKIA|ASIA)[A-Z0-9]{16}\b/' => '[REDACTED_AWS_KEY]',
        // Bearer/Basic authorization header values
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._-]+\b/i' => '[REDACTED_AUTH_HEADER]',
        // Credit card-like sequences (13-19 digits, optional spaces/dashes)
        '/\b(?:\d[ -]?){13,19}\b/' => '[REDACTED_CARD_NUMBER]',
        // Email addresses
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/' => '[REDACTED_EMAIL]',
    ];

    /** @var array<string, true> */
    private array $exactKeys;

    /** @var list<string> */
    private array $fuzzyPatterns;

    /** @var array<string, string> */
    private array $valuePatterns;

    /**
     * @param list<string> $keys
     * @param array<string, string> $valuePatterns Additional regex => replacement
     *        pairs merged with VALUE_PATTERNS, scrubbed from string values/messages.
     */
    public function __construct(
        array $keys = [],
        private readonly string $replacement = self::DEFAULT_REPLACEMENT,
        private readonly int $maxDepth = 8,
        array $valuePatterns = [],
        private readonly bool $scanValues = true,
    ) {
        $this->exactKeys = [];
        $this->fuzzyPatterns = [];
        $this->valuePatterns = [...self::VALUE_PATTERNS, ...$valuePatterns];

        foreach ($keys === [] ? self::DEFAULT_KEYS : $keys as $key) {
            if (str_contains($key, '*')) {
                $this->fuzzyPatterns[] = '/^' . str_replace('\*', '.*', preg_quote($this->normalizeKey($key), '/')) . '$/i';
                continue;
            }

            $this->exactKeys[$this->normalizeKey($key)] = true;
        }
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->sanitizeMessage($record->message);
        if ($this->scanValues) {
            $message = $this->redactPatternsInString($message);
        }

        return $record->with(
            message: $message,
            context: $this->redactValue($record->context),
            extra: $this->redactValue($record->extra),
        );
    }

    private function redactValue(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            return $this->replacement;
        }

        if ($depth >= $this->maxDepth) {
            return '[MAX_DEPTH]';
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue($childValue, $depth + 1, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if ($this->scanValues && is_string($value)) {
            return $this->redactPatternsInString($value);
        }

        return $value;
    }

    private function redactPatternsInString(string $value): string
    {
        foreach ($this->valuePatterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value) ?? $value;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = $this->normalizeKey($key);
        if (isset($this->exactKeys[$normalized])) {
            return true;
        }

        foreach ($this->fuzzyPatterns as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(strtr($key, ['-' => '_', '.' => '_']));
    }

    private function sanitizeMessage(string $message): string
    {
        return str_replace(["\r", "\n"], ['\\r', '\\n'], $message);
    }
}
