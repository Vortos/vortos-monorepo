<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Residency;

/** Thrown when a write or export operation would violate a tenant's residency policy. */
final class ResidencyViolationException extends \RuntimeException {}
