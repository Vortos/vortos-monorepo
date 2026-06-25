<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Preflight\PreflightReport;
use Vortos\Deploy\Target\TargetStatus;

/**
 * The result of a {@see DeployRunner} or {@see RollbackRunner} run — a console-free,
 * fully serializable value the commands render to text or JSON.
 */
final readonly class DeployOutcome
{
    private function __construct(
        public DeployOutcomeStatus $status,
        public string $env,
        public ?PreflightReport $report = null,
        public ?DeployPlan $plan = null,
        public ?string $preview = null,
        public ?TargetStatus $targetStatus = null,
        public ?string $runId = null,
        public ?string $rollbackReason = null,
    ) {}

    public static function refused(string $env, PreflightReport $report): self
    {
        return new self(DeployOutcomeStatus::Refused, $env, report: $report);
    }

    public static function dryRun(string $env, PreflightReport $report, DeployPlan $plan, string $preview): self
    {
        return new self(DeployOutcomeStatus::DryRun, $env, report: $report, plan: $plan, preview: $preview);
    }

    public static function deployed(
        string $env,
        DeployPlan $plan,
        string $preview,
        TargetStatus $status,
        ?PreflightReport $report = null,
    ): self {
        return new self(
            DeployOutcomeStatus::Deployed,
            $env,
            report: $report,
            plan: $plan,
            preview: $preview,
            targetStatus: $status,
        );
    }

    public static function rolledBack(
        string $env,
        string $reason,
        ?DeployPlan $plan = null,
        ?TargetStatus $status = null,
        ?PreflightReport $report = null,
    ): self {
        return new self(
            DeployOutcomeStatus::RolledBack,
            $env,
            report: $report,
            plan: $plan,
            targetStatus: $status,
            rollbackReason: $reason,
        );
    }

    public function exitCode(): int
    {
        return $this->status->isSuccess() ? 0 : 1;
    }

    public function isClear(): bool
    {
        return $this->status->isSuccess();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'env' => $this->env,
            'exit_code' => $this->exitCode(),
            'run_id' => $this->runId,
            'rollback_reason' => $this->rollbackReason,
            'plan' => $this->plan?->toArray(),
            'target_status' => $this->targetStatus?->toArray(),
            'report' => $this->report?->toArray(),
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        );
    }
}
