<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;

interface MaintenanceSilenceStoreInterface
{
    public function add(MaintenanceSilence $silence): void;

    /** @return list<MaintenanceSilence> active (not yet expired) silences covering $ruleId at $now */
    public function active(string $ruleId, DateTimeImmutable $now): array;

    /** Housekeeping only — auto-expiry is enforced by {@see active()} regardless of whether this runs. */
    public function purgeExpired(DateTimeImmutable $now): int;
}
