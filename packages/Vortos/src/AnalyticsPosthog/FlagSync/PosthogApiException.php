<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\FlagSync;

use RuntimeException;

/**
 * A PostHog management-API call failed.
 *
 * Thrown rather than swallowed on purpose: the flag-definition sync is a control-plane
 * operation, and the operator running it needs to know *why* it failed — a wrong project
 * id, a key without `feature_flag:write`, or a payload PostHog rejected all look identical
 * from a boolean return. Callers on a request path (the event listener) catch this; the CLI
 * prints it.
 *
 * The message never includes the API key. `$body` is PostHog's own error body, truncated.
 */
final class PosthogApiException extends RuntimeException
{
    private const MAX_BODY = 500;

    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly string $body = '',
    ) {
        $detail = $body !== '' ? ' — ' . substr($body, 0, self::MAX_BODY) : '';
        parent::__construct($status > 0 ? "{$message} (HTTP {$status}){$detail}" : "{$message}{$detail}");
    }
}
