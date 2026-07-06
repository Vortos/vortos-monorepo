<?php

declare(strict_types=1);

namespace Vortos\Backup\Doctor;

use Vortos\Backup\Domain\DatabaseEngine;

/**
 * The aggregate verdict for one engine's client toolchain: the per-binary findings, the server
 * major they were gated against (if known), and whether the toolchain can run a backup at all.
 */
final readonly class ToolchainReport
{
    /** @param list<BinaryFinding> $findings */
    public function __construct(
        public DatabaseEngine $engine,
        public array $findings,
        public ?int $serverMajor = null,
    ) {
    }

    public function isSatisfied(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<BinaryFinding> */
    public function failures(): array
    {
        return array_values(array_filter($this->findings, static fn (BinaryFinding $f): bool => $f->isFailure()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'engine' => $this->engine->value,
            'server_major' => $this->serverMajor,
            'satisfied' => $this->isSatisfied(),
            'findings' => array_map(static fn (BinaryFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
