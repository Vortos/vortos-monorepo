<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

/**
 * Severity of a captured error, ordered low → high.
 */
enum ErrorSeverity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

    /** Monotonic rank for thresholding / routing (higher = more severe). */
    public function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
            self::Fatal => 4,
        };
    }
}
