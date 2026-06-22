<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

/**
 * Thrown at a write/storage boundary when a value cannot represent its declared
 * {@see \Vortos\FeatureFlags\FlagValueType}, is corrupt, or violates the JSON
 * size/depth bounds. Never thrown on the evaluation hot path — evaluation always
 * falls back to the flag's safe default instead.
 */
final class InvalidFlagValueException extends \InvalidArgumentException
{
}
