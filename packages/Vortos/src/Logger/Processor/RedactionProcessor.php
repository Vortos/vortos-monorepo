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

    /** @var list<string> */
    private array $patterns;

    /**
     * @param list<string> $keys
     */
    public function __construct(
        array $keys = [],
        private readonly string $replacement = self::DEFAULT_REPLACEMENT,
        private readonly int $maxDepth = 8,
    ) {
        $this->patterns = array_map(
            static fn (string $key): string => '/(?:^|[_\-.])' . preg_quote($key, '/') . '(?:$|[_\-.])/i',
            $keys === [] ? [
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
            ] : $keys,
        );
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
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
        foreach ($this->patterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}
