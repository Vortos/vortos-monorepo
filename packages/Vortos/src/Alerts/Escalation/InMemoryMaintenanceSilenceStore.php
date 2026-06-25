<?php

declare(strict_types=1);

namespace Vortos\Alerts\Escalation;

use DateTimeImmutable;

final class InMemoryMaintenanceSilenceStore implements MaintenanceSilenceStoreInterface
{
    /** @var array<string, MaintenanceSilence> */
    private array $silences = [];

    public function add(MaintenanceSilence $silence): void
    {
        $this->silences[$silence->id] = $silence;
    }

    public function active(string $ruleId, DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->silences,
            static fn (MaintenanceSilence $s): bool => $s->coversRule($ruleId) && $s->isActiveAt($now),
        ));
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $before = count($this->silences);
        $this->silences = array_filter($this->silences, static fn (MaintenanceSilence $s): bool => $s->expiresAt > $now);

        return $before - count($this->silences);
    }
}
