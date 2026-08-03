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
     * The conflicting id, but only when it is the kind of entity the caller asked for.
     *
     * The id is recovered from a free-text message, and what a caller does with it is
     * act on it — bill that customer, attach to that subscription. A message whose
     * shape shifts, or one that names a different entity than the operation was about,
     * must not be able to redirect that. Callers state the prefix they expect
     * ('ctm_' for a customer) and get null for anything else, which sends them down
     * the same path as a conflict that named nothing at all.
     */
    public function conflictingEntityIdOfType(string $expectedPrefix): ?string
    {
        if ($this->conflictingEntityId === null) {
            return null;
        }

        return str_starts_with($this->conflictingEntityId, $expectedPrefix)
            ? $this->conflictingEntityId
            : null;
    }

    /**
     * Pulls a Paddle entity id out of a conflict message.
     *
     * Paddle ids are a short type prefix, an underscore and an alphanumeric body
     * (ctm_…, biz_…, pri_…) — a shape nothing else in these messages has.
     *
     * Bounded explicitly on both ends rather than by \b, so that a longer lookalike is
     * not matched at all rather than truncated to a prefix that happens to parse. This
     * says nothing about *which* entity was found, though — recovering the wrong id is
     * worse than recovering none, so callers should read
     * {@see conflictingEntityIdOfType()} rather than the raw property.
     */
    public static function parseEntityId(string $message): ?string
    {
        if (preg_match('/(?<![0-9a-zA-Z_])([a-z]{2,5}_[0-9a-zA-Z]{6,})(?![0-9a-zA-Z_])/', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
