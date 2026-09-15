<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

/** One way the running configuration departs from the declared file. Names and sources, never values. */
final readonly class PostgresConfigDrift
{
    public function __construct(
        public PostgresConfigDriftKind $kind,
        public string $setting,
        /** Where it came from: a file path, or the source name. */
        public string $origin,
    ) {}

    /** @return array{kind: string, setting: string, origin: string} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'setting' => $this->setting, 'origin' => $this->origin];
    }
}
