<?php

declare(strict_types=1);

namespace Vortos\Alerts\Integration;

use DateTimeImmutable;
use Vortos\Alerts\DispatchResult;

/**
 * A periodic evaluator: samples some subsystem, evaluates the rules it owns, dispatches what fires.
 *
 * Extracted from the five sources that already shared this exact signature but had no common type
 * and — more importantly — no driver. Registering a source is not what makes it fire; something has
 * to tick it. Tagging with {@see \Vortos\Alerts\DependencyInjection\AlertsExtension::SOURCE_TAG}
 * hands it to {@see \Vortos\Alerts\Runtime\AlertSourceTicker}, which the framework schedules.
 */
interface AlertSourceInterface
{
    /**
     * @return list<DispatchResult> results for whatever fired this tick; empty when nothing did
     */
    public function tick(string $env, DateTimeImmutable $now): array;
}
