<?php

declare(strict_types=1);

namespace Vortos\Backup\Event;

/**
 * Severity of a backup lifecycle event. Maps cleanly onto the Block-17 alert routing
 * matrix when an alerting sink is later registered (critical → page).
 */
enum BackupEventSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
