<?php

declare(strict_types=1);

namespace Vortos\Audit\Exception;

/**
 * Thrown (in strict mode) when code records an action key that no provider declared.
 * Catches typos and undeclared actions at the call site rather than letting an
 * unqueryable string leak into the permanent trail.
 */
final class UnknownAuditActionException extends \RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Audit action '{$key}' is not declared in any AuditActionProvider. " .
            'Add it to a RegisteredAction vocabulary, or run the recorder in non-strict mode.',
        );
    }
}
