<?php

declare(strict_types=1);

namespace Vortos\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the user ID from the envelope metadata into the marked handler parameter.
 *
 * The parameter type must be string (nullable — value may be absent).
 * Identifies the user whose action produced the event. Use for audit logging,
 * ownership assignment, and user-scoped projections.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[UserId] ?string $userId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class UserId {}
