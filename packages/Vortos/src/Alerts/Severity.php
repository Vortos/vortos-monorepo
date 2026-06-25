<?php

declare(strict_types=1);

namespace Vortos\Alerts;

/**
 * The one severity vocabulary every alert is normalized into for routing — adapters
 * map `BackupEventSeverity` / `Observability\Sink\ErrorSeverity` / a health probe's
 * tri-state onto this enum so the routing matrix never has to know about a source's
 * own severity shape.
 */
enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /** Critical pages; Info/Warning route to chat (the default routing contract). */
    public function isPaging(): bool
    {
        return $this === self::Critical;
    }

    /** Higher is more severe — used to order multi-channel fan-out / digesting. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }
}
