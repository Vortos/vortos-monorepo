<?php

declare(strict_types=1);

namespace Vortos\Alerts\Schedule;

use Psr\Clock\ClockInterface;
use Vortos\Alerts\Runtime\AlertSourceTicker;
use Vortos\Cqrs\Attribute\AsCommandHandler;

/**
 * Handles the scheduled {@see EvaluateAlertSourcesCommand}.
 *
 * Thin: {@see AlertSourceTicker} owns per-source failure isolation, and each source owns its own
 * sampling. Exceptions are deliberately NOT swallowed here — the ticker has already contained
 * anything a single source can throw, so a failure reaching this point means the alerting pipeline
 * itself is broken, and that must surface as a failed schedule run rather than a silent no-op.
 */
#[AsCommandHandler]
final class EvaluateAlertSourcesHandler
{
    public function __construct(
        private readonly AlertSourceTicker $ticker,
        private readonly ClockInterface $clock,
        private readonly string $env = 'prod',
    ) {}

    public function __invoke(EvaluateAlertSourcesCommand $command): void
    {
        $this->ticker->tick($this->env, $this->clock->now());
    }
}
