<?php

declare(strict_types=1);

namespace Vortos\Backup\Event;

/**
 * A sink for backup lifecycle events — the seam Block 17 plugs an alerting notifier
 * into without any change to Block 19.
 *
 * Implementations MUST NOT throw: a broken alerter must never fail (or mask) a
 * backup. Fan-out + failure isolation is handled by {@see CompositeBackupEventSink}.
 */
interface BackupEventSinkInterface
{
    public function emit(BackupEvent $event): void;
}
