<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Hook\Exception;

use RuntimeException;
use Throwable;

/**
 * Wraps a hook execution failure for structured logging.
 *
 * Never thrown publicly — instantiated only inside HookRunner::invoke()
 * to provide consistent log context when a hook throws.
 */
final class HookExecutionException extends RuntimeException
{
    public static function forHook(string $serviceId, string $hookType, Throwable $previous):self
    {
        return new self(
            sprintf('Hook [%s] of type [%s] failed: %s', $serviceId, $hookType, $previous->getMessage()),
            0,
            $previous
        );
    } 
}