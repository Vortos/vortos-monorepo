<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Drift;

use Vortos\Iac\Lifecycle\IacDriftReport;

final class DriftAlertEmitter
{
    private readonly object $notifier;

    public function __construct(object $notifier)
    {
        $this->notifier = $notifier;
    }

    public function emit(string $environment, IacDriftReport $report): void
    {
        if (!$report->hasDrift) {
            return;
        }

        if (method_exists($this->notifier, 'notify')) {
            $this->notifier->notify(
                'iac.drift',
                sprintf('Infrastructure drift detected in %s: %s', $environment, $report->summary),
            );
        }
    }
}
