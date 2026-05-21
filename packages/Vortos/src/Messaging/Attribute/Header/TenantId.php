<?php

declare(strict_types=1);

namespace Vortos\Messaging\Attribute\Header;

use Attribute;

/**
 * Injects the tenant ID from the envelope metadata into the marked handler parameter.
 *
 * The parameter type must be string (nullable — value may be absent).
 * Use in multi-tenant systems to route data to the correct database/schema/context
 * without coupling the handler to the full EventEnvelope.
 *
 * Example:
 *   public function __invoke(OrderPlaced $event, #[TenantId] ?string $tenantId): void {}
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class TenantId {}
