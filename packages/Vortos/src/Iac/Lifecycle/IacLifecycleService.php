<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

use Vortos\Iac\Exception\DestructiveChangeRefusedException;
use Vortos\Iac\Exception\PlanStaleException;
use Vortos\Iac\Lifecycle\Audit\IacAuditSinkInterface;
use Vortos\Iac\Lifecycle\Audit\LifecycleEvent;
use Vortos\Iac\Lifecycle\Policy\PlanPolicyInterface;
use Vortos\Iac\Exception\PolicyViolationException;

final class IacLifecycleService
{
    public function __construct(
        private readonly IacEngineInterface $engine,
        private readonly PlanPolicyInterface $policy,
        private readonly IacAuditSinkInterface $auditSink,
        private readonly int $maxDestructiveProd = 0,
        private readonly int $maxDestructiveNonProd = 5,
    ) {}

    public function init(IacWorkspace $ws, IacExecutionContext $ctx): void
    {
        $this->engine->init($ws, $ctx);
    }

    public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
    {
        $plan = $this->engine->plan($ws, $ctx);

        $this->emitAudit(LifecyclePhase::Plan, $ws, $plan->rawJsonDigest, $plan->toReviewableDiff());

        return $plan;
    }

    public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult
    {
        $this->guardPlanFile($plan);
        $this->guardBlastRadius($plan, $ws->environment, $ctx->allowDestructive);
        $this->guardPolicy($plan);

        $result = $this->engine->apply($ws, $plan, $ctx);

        $this->emitAudit(
            LifecyclePhase::Apply,
            $ws,
            $plan->rawJsonDigest,
            sprintf(
                'applied=%d failed=%d duration=%dms planFileDigest=%s',
                $result->applied,
                $result->failed,
                $result->durationMs,
                substr($plan->planFileDigest, 0, 12),
            ),
        );

        return $result;
    }

    public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult
    {
        $result = $this->engine->destroy($ws, $ctx);

        $this->emitAudit(
            LifecyclePhase::Destroy,
            $ws,
            '',
            sprintf('destroyed=%d failed=%d duration=%dms', $result->destroyed, $result->failed, $result->durationMs),
        );

        return $result;
    }

    public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
    {
        return $this->engine->show($ws, $ctx);
    }

    private function guardPlanFile(IacPlan $plan): void
    {
        if (!file_exists($plan->planFile)) {
            throw PlanStaleException::planFileMissing($plan->planFile);
        }

        $currentDigest = hash_file('sha256', $plan->planFile);

        if ($currentDigest !== $plan->planFileDigest) {
            throw PlanStaleException::digestMismatch($plan->planFileDigest, (string) $currentDigest);
        }
    }

    private function guardBlastRadius(IacPlan $plan, string $environment, bool $allowDestructive): void
    {
        if ($allowDestructive) {
            return;
        }

        $max = $this->isProd($environment) ? $this->maxDestructiveProd : $this->maxDestructiveNonProd;
        $count = $plan->destructiveCount();

        if ($count > $max) {
            $addresses = [];
            foreach ($plan->resourceChanges as $change) {
                if ($change->isDestructive()) {
                    $addresses[] = $change->address;
                }
            }

            throw DestructiveChangeRefusedException::overLimit($count, $max, $addresses);
        }
    }

    private function guardPolicy(IacPlan $plan): void
    {
        $result = $this->policy->evaluate($plan);

        if (!$result->passed()) {
            throw PolicyViolationException::fromResult($result);
        }
    }

    private function isProd(string $environment): bool
    {
        return in_array($environment, ['prod', 'production'], true);
    }

    private function emitAudit(LifecyclePhase $phase, IacWorkspace $ws, string $planDigest, string $summary): void
    {
        try {
            $binaryVersion = (string) ($this->engine->capabilities()->constraint('version') ?? 'unknown');
        } catch (\Throwable) {
            $binaryVersion = 'unknown';
        }

        $this->auditSink->record(new LifecycleEvent(
            $phase,
            $ws->environment,
            $planDigest,
            get_current_user(),
            $summary,
            (string) $binaryVersion,
            (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ));
    }
}
