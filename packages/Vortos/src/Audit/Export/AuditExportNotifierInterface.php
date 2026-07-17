<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * Narrow port the export consumer calls when a job reaches a terminal state, so the package
 * can tell the requester their export is ready (or failed) without depending on any concrete
 * notification system. The app implements this — e.g. dispatching an in-app + email + web-push
 * notification with a deeplink to the export — and wires it in. Optional: if no implementation
 * is bound, the consumer simply skips notification (the job status is still authoritative and
 * pollable).
 */
interface AuditExportNotifierInterface
{
    public function exportReady(AuditExportJob $job, AuditExportResult $result): void;

    public function exportFailed(AuditExportJob $job, string $error): void;
}
