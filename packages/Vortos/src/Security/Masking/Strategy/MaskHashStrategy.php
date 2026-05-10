<?php

declare(strict_types=1);

namespace Vortos\Security\Masking\Strategy;

use Vortos\Security\Contract\MaskingStrategyInterface;

/**
 * Replaces the value with its SHA-256 hex digest (first 16 chars).
 *
 * Useful when you need to correlate log entries for the same value
 * (e.g., trace all requests with the same API key) without exposing the value.
 *
 * The truncated hash is not reversible and has no collision risk in practice
 * for log correlation purposes.
 */
final class MaskHashStrategy implements MaskingStrategyInterface
{
    public function mask(string $value): string
    {
        return 'sha256:' . substr(hash('sha256', $value), 0, 16) . '...';
    }
}
