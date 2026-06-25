<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final class IacDriftAuditor implements IacDriftAuditorInterface
{
    public function __construct(
        private readonly IacLifecycleService $lifecycle,
        private readonly string $workingDir,
    ) {}

    public function audit(string $environment): IacDriftReport
    {
        try {
            $ws = IacWorkspace::forEnvironment($environment, $this->workingDir);
            $ctx = new IacExecutionContext();

            $plan = $this->lifecycle->show($ws, $ctx);

            if (!$plan->hasChanges()) {
                return IacDriftReport::clean();
            }

            return IacDriftReport::drifted($plan->toReviewableDiff());
        } catch (\Throwable $e) {
            return IacDriftReport::unreachable($e->getMessage());
        }
    }
}
