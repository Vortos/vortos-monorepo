<?php

declare(strict_types=1);

namespace Vortos\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the causation ID from the envelope metadata into the marked handler parameter.
 *
 * The parameter type must be string (nullable — value may be absent).
 * Identifies the command or event that directly caused this event.
 * Use for audit trails and causal chain reconstruction.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[CausationId] ?string $causationId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class CausationId {}
