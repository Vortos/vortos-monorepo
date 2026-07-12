<?php

declare(strict_types=1);

namespace Vortos\Audit\Query;

/**
 * Aggregate counts for the current filter, powering a console's faceted filter rail:
 * how many records match per action, per sensitivity, and per outcome — computed over the
 * whole filtered set (ignoring pagination), so the counts don't shift as you scroll.
 */
final readonly class AuditFacets
{
    /**
     * @param array<string, int> $byAction      action key => count
     * @param array<string, int> $bySensitivity sensitivity value => count
     * @param array<string, int> $byOutcome     outcome value => count
     */
    public function __construct(
        public array $byAction,
        public array $bySensitivity,
        public array $byOutcome,
    ) {}

    /**
     * @return array{action: array<string,int>, sensitivity: array<string,int>, outcome: array<string,int>}
     */
    public function toArray(): array
    {
        return [
            'action'      => $this->byAction,
            'sensitivity' => $this->bySensitivity,
            'outcome'     => $this->byOutcome,
        ];
    }
}
