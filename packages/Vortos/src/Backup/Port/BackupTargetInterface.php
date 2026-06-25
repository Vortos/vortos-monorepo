<?php

declare(strict_types=1);

namespace Vortos\Backup\Port;

use Vortos\Backup\Domain\BackupRequest;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\OpsKit\Driver\DriverInterface;

/**
 * A swappable backup *target*: knows how to produce a streamed dump of one database
 * engine. Drivers: `postgres`, `mongo` (in-core); others via `make:driver`.
 *
 * Extends {@see DriverInterface}, so every target reports its {@see capabilities()}
 * (consistent snapshot / replica-sourced / PITR / streaming) — validated at config
 * time and asserted by the TCK. A target asked for a capability it does not declare
 * MUST raise {@see \Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException},
 * never silently degrade.
 */
interface BackupTargetInterface extends DriverInterface
{
    /**
     * Produce a one-shot dump stream. MUST NOT buffer the whole dump in memory or on
     * a tracked/persistent disk path — the bytes flow straight to the consumer.
     */
    public function dump(BackupRequest $request): BackupStream;

    public function engine(): DatabaseEngine;
}
