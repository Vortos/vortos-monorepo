<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

/**
 * Paddle rejected a write because it collides with an entity that already exists.
 *
 * The id of that entity is usually the whole point — creating a customer for an
 * address Paddle already knows is a normal outcome, not a failure — and Paddle only
 * states it in the human-readable detail ("customer email conflicts with customer of
 * id ctm_123"). That string is parsed here, once, so callers stop regexing exception
 * messages to recover it.
 */
final class PaddleConflictException extends PaddleApiException
{
    public function __construct(
        string      $message,
        ?string     $errorCode = null,
        ?string     $errorType = null,
        ?\Throwable $previous = null,
        /** Paddle id of the pre-existing entity, when the detail names one. */
        public readonly ?string $conflictingEntityId = null,
    ) {
        parent::__construct($message, $errorCode, $errorType, $previous);
    }

    /**
     * Pulls a Paddle entity id out of a conflict message.
     *
     * Paddle ids are a short type prefix, an underscore and an alphanumeric body
     * (ctm_…, biz_…, pri_…) — a shape nothing else in these messages has.
     */
    public static function parseEntityId(string $message): ?string
    {
        if (preg_match('/\b([a-z]{2,5}_[0-9a-zA-Z]{6,})\b/', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
