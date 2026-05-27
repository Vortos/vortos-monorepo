<?php

declare(strict_types=1);

namespace Vortos\Domain\Attribute;

/**
 * Marks a domain service for automatic container registration.
 *
 * Domain folders are excluded from the default App\ service scan so aggregates,
 * value objects, events, and errors do not become services. This attribute is
 * the explicit opt-in for stateless domain services that need dependency
 * injection.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsDomainService
{
}
