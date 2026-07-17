<?php

declare(strict_types=1);

namespace Vortos\AuditAdmin\Http\Serializer;

use Vortos\Audit\Export\AuditExportJob;

/**
 * Flattens an {@see AuditExportJob} into the JSON shape the export consoles poll. The download
 * URL is passed in (minted fresh per request by the service) rather than read off the job, so a
 * response never carries a stale link.
 */
final class AuditExportJobPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(AuditExportJob $job, ?string $downloadUrl = null): array
    {
        return [
            'id'            => $job->id,
            'status'        => $job->status()->value,
            'scope'         => $job->scope->value,
            'tenantId'      => $job->tenantId,
            'requestedBy'   => [
                'id'    => $job->requestedByActorId,
                'label' => $job->requestedByLabel,
            ],
            'filter'        => $job->filter->toArray(),
            'recordCount'   => $job->recordCount(),
            'byteSize'      => $job->byteSize(),
            'contentSha256' => $job->contentSha256(),
            'error'         => $job->error(),
            'createdAt'     => $job->createdAt->format(\DATE_ATOM),
            'updatedAt'     => $job->updatedAt()->format(\DATE_ATOM),
            'expiresAt'     => $job->expiresAt()?->format(\DATE_ATOM),
            'downloadUrl'   => $downloadUrl,
        ];
    }
}
