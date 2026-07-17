<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

/**
 * Wire contract carried over the message bus from the request path (an org/platform admin
 * clicking "Export") to the export consumer. A pure, final readonly POPO with no methods
 * beyond the constructor (messaging wire-contract rules forbid extra methods) — it carries
 * only the job id; the consumer loads the {@see AuditExportJob} from the store and runs it.
 * Keeping the payload id-only means the filter/scope live in one authoritative place (the
 * job row) and can't drift between producer and consumer.
 */
final readonly class AuditExportRequested
{
    public function __construct(
        public string $exportId,
    ) {}
}
