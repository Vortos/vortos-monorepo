<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use RuntimeException;
use Vortos\Backup\Drill\Container\ExecResult;

final class PgBackRestCommandFailedException extends RuntimeException
{
    /**
     * pgBackRest prints its full option list on every command, with every secret replaced by
     * `<redacted>`, so its own output is safe to carry into a log line or an alert.
     *
     * @param list<string> $arguments
     */
    public static function for(array $arguments, ExecResult $result): self
    {
        return new self(sprintf(
            'pgbackrest %s exited %d: %s',
            implode(' ', $arguments),
            $result->exitCode,
            $result->tail(),
        ));
    }
}
