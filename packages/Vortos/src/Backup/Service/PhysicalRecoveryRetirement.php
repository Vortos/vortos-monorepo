<?php

declare(strict_types=1);

namespace Vortos\Backup\Service;

/**
 * What retiring the framework's physical point-in-time recovery found, or removed.
 *
 * A dry run counts what exists; an applied run counts what was actually deleted. Objects the store
 * refused because they are immutable (Object Lock) are left in place, with their catalog rows, and
 * counted as locked so a later run can finish the job once the lock expires.
 */
final readonly class PhysicalRecoveryRetirement
{
    public function __construct(
        public bool $applied,
        public int $bases,
        public int $baseBytes,
        public int $segments,
        public int $segmentBytes,
        public int $locked,
    ) {}

    /** @return array<string, int|bool> */
    public function toArray(): array
    {
        return [
            'applied'       => $this->applied,
            'bases'         => $this->bases,
            'base_bytes'    => $this->baseBytes,
            'segments'      => $this->segments,
            'segment_bytes' => $this->segmentBytes,
            'locked'        => $this->locked,
        ];
    }
}
