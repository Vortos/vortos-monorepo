<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

final readonly class DedupeOutcome
{
    public function __construct(
        public DedupeDecision $decision,
        public AlertState $nextState,
    ) {}
}
