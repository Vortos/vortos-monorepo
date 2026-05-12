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

    /** @var array<string, true> */
    private array $exactKeys;

    /** @var list<string> */
    private array $fuzzyPatterns;

    /**
     * @param list<string> $keys
     */
    public function __construct(
        array $keys = [],
        private readonly string $replacement = self::DEFAULT_REPLACEMENT,
        private readonly int $maxDepth = 8,
    ) {
        $this->exactKeys = [];
        $this->fuzzyPatterns = [];

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
        return $record->with(
            message: $this->sanitizeMessage($record->message),
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
