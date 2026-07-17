<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * Lifecycle of an async audit export job.
 *
 *   Queued  → accepted, waiting for the export consumer to pick it up.
 *   Running → the consumer is streaming the trail to the object store.
 *   Ready   → body + manifest persisted; downloadable until the artifact expires.
 *   Failed  → the run errored; `error` carries a safe summary.
 *   Expired → the artifact was garbage-collected after its retention window.
 */
enum AuditExportStatus: string
{
    case Queued  = 'queued';
    case Running = 'running';
    case Ready   = 'ready';
    case Failed  = 'failed';
    case Expired = 'expired';

    /** No further transitions happen once a job is terminal. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Ready, self::Failed, self::Expired => true,
            self::Queued, self::Running              => false,
        };
    }

    public function isDownloadable(): bool
    {
        return $this === self::Ready;
    }
}
