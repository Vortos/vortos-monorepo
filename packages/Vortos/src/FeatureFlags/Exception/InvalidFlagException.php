<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

/**
 * Thrown at write time when a flag's configuration is invalid: a prerequisite cycle,
 * a permission flag targeting untrusted context, or variant weights over 100%.
 * Rejecting at write keeps the evaluator's hot path total and loop-free.
 */
final class InvalidFlagException extends \InvalidArgumentException
{
}
