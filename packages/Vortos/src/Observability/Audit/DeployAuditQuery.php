<?php

declare(strict_types=1);

namespace Vortos\Observability\Audit;

/**
 * Immutable filter for read-side audit queries / exports. All fields optional;
 * null means "no constraint on this dimension".
 */
final readonly class DeployAuditQuery
{
    public function __construct(
        public ?string $env = null,
        public ?string $actorId = null,
        public ?string $buildId = null,
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public int $limit = 1000,
    ) {
        if ($this->limit < 1) {
            throw new \InvalidArgumentException('Audit query limit must be >= 1.');
        }
    }
}
