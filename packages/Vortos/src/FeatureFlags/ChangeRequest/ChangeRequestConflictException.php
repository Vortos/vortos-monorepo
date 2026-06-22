<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest;

/**
 * Thrown by {@see ChangeRequestService::apply()} when one or more competing change
 * requests for the same flag + environment were applied after this request was
 * created. The controller maps this to HTTP 409 Conflict.
 */
final class ChangeRequestConflictException extends \DomainException
{
    /** @param string[] $conflictingIds IDs of the competing change requests */
    public function __construct(
        public readonly array $conflictingIds,
        string $flagName,
    ) {
        parent::__construct(sprintf(
            'Flag "%s" was modified by %d competing change request(s) since this request was created: %s.',
            $flagName,
            count($conflictingIds),
            implode(', ', $conflictingIds),
        ));
    }
}
