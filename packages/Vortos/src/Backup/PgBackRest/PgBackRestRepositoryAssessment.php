<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

final readonly class PgBackRestRepositoryAssessment
{
    /**
     * @param list<string>         $problems each one a reason to page, in words an operator can act on
     * @param array<string, mixed> $facts    what was measured, for the alert and the dashboard
     */
    public function __construct(
        public PgBackRestRepositoryStatus $status,
        public array $problems,
        public array $facts,
    ) {}

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return ['status' => $this->status->value, 'problems' => $this->problems] + $this->facts;
    }
}
