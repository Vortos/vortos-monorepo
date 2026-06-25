<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

final readonly class FlapOutcome
{
    public function __construct(
        public AlertState $nextState,
        public bool $shouldEscalate,
        public bool $isDamped,
    ) {}
}
