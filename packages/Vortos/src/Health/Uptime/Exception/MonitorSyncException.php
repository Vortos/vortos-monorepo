<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Exception;

/** Raised when a provider sync write fails. Never carries secret material (§6.3). */
final class MonitorSyncException extends UptimeMonitorException
{
    public static function forFailure(string $monitorKey, string $reason): self
    {
        return new self(sprintf('Failed to sync uptime monitor "%s": %s', $monitorKey, $reason));
    }
}
