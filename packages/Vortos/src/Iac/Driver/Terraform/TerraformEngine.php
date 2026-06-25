<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

use Vortos\Iac\Driver\Terraform\Argv\ApplyArgv;
use Vortos\Iac\Driver\Terraform\Argv\DestroyArgv;
use Vortos\Iac\Driver\Terraform\Argv\InitArgv;
use Vortos\Iac\Driver\Terraform\Argv\PlanArgv;
use Vortos\Iac\Driver\Terraform\Argv\ShowArgv;
use Vortos\Iac\Exception\IacException;
use Vortos\Iac\Lifecycle\IacApplyResult;
use Vortos\Iac\Lifecycle\IacDestroyResult;
use Vortos\Iac\Lifecycle\IacEngineCapability;
use Vortos\Iac\Lifecycle\IacEngineInterface;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacWorkspace;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('terraform')]
final class TerraformEngine implements IacEngineInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly BinaryResolver $resolver,
        private readonly PlanJsonParser $parser,
    ) {}

    public function init(IacWorkspace $ws, IacExecutionContext $ctx): void
    {
        $binary = $this->resolver->resolve($ctx->binaryHint);
        $argv = InitArgv::build($binary);
        $env = $this->buildEnv($ctx);

        $outcome = $this->runner->run($argv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if (!$outcome->isSuccess()) {
            throw new IacException(sprintf(
                "terraform init failed (exit %d): %s",
                $outcome->exitCode,
                $this->redact($outcome->stderr, $ctx),
            ));
        }
    }

    public function plan(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
    {
        $binary = $this->resolver->resolve($ctx->binaryHint);
        $planFile = $ws->workingDir . '/tfplan-' . $ws->stateKey . '.bin';

        $argv = PlanArgv::build(
            $binary,
            $planFile,
            $ctx->parallelism,
            $ctx->lockTimeoutSeconds,
        );

        $env = $this->buildEnv($ctx);
        $outcome = $this->runner->run($argv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if ($outcome->exitCode !== 0 && $outcome->exitCode !== 2) {
            throw new IacException(sprintf(
                "terraform plan failed (exit %d): %s",
                $outcome->exitCode,
                $this->redact($outcome->stderr, $ctx),
            ));
        }

        return $this->parsePlan($binary, $ws, $planFile, $ctx);
    }

    public function apply(IacWorkspace $ws, IacPlan $plan, IacExecutionContext $ctx): IacApplyResult
    {
        $binary = $this->resolver->resolve($ctx->binaryHint);
        $argv = ApplyArgv::build($binary, $plan->planFile);
        $env = $this->buildEnv($ctx);

        $outcome = $this->runner->run($argv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if (!$outcome->isSuccess()) {
            throw new IacException(sprintf(
                "terraform apply failed (exit %d): %s",
                $outcome->exitCode,
                $this->redact($outcome->stderr, $ctx),
            ));
        }

        $applied = 0;
        $failed = 0;
        $combined = $outcome->stdout . "\n" . $outcome->stderr;

        if (preg_match('/(\d+) added/', $combined, $m)) {
            $applied += (int) $m[1];
        }
        if (preg_match('/(\d+) changed/', $combined, $m)) {
            $applied += (int) $m[1];
        }
        if (preg_match('/Error:/', $combined)) {
            $failed++;
        }

        return new IacApplyResult(
            $applied,
            $failed,
            $outcome->durationMs,
            hash('sha256', $outcome->stdout),
        );
    }

    public function destroy(IacWorkspace $ws, IacExecutionContext $ctx): IacDestroyResult
    {
        $binary = $this->resolver->resolve($ctx->binaryHint);
        $planFile = $ws->workingDir . '/tfplan-destroy-' . $ws->stateKey . '.bin';

        $planArgv = DestroyArgv::buildPlan(
            $binary,
            $planFile,
            $ctx->parallelism,
            $ctx->lockTimeoutSeconds,
        );

        $env = $this->buildEnv($ctx);
        $planOutcome = $this->runner->run($planArgv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if ($planOutcome->exitCode !== 0 && $planOutcome->exitCode !== 2) {
            throw new IacException(sprintf(
                "terraform plan -destroy failed (exit %d): %s",
                $planOutcome->exitCode,
                $this->redact($planOutcome->stderr, $ctx),
            ));
        }

        $applyArgv = ApplyArgv::build($binary, $planFile);
        $applyOutcome = $this->runner->run($applyArgv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if (!$applyOutcome->isSuccess()) {
            throw new IacException(sprintf(
                "terraform destroy failed (exit %d): %s",
                $applyOutcome->exitCode,
                $this->redact($applyOutcome->stderr, $ctx),
            ));
        }

        $destroyed = 0;
        $failed = 0;
        $combined = $applyOutcome->stdout . "\n" . $applyOutcome->stderr;

        if (preg_match('/(\d+) destroyed/', $combined, $m)) {
            $destroyed = (int) $m[1];
        }
        if (preg_match('/Error:/', $combined)) {
            $failed++;
        }

        return new IacDestroyResult($destroyed, $failed, $applyOutcome->durationMs);
    }

    public function show(IacWorkspace $ws, IacExecutionContext $ctx): IacPlan
    {
        $binary = $this->resolver->resolve($ctx->binaryHint);
        $planFile = $ws->workingDir . '/tfplan-show-' . $ws->stateKey . '.bin';

        $planArgv = PlanArgv::build(
            $binary,
            $planFile,
            $ctx->parallelism,
            $ctx->lockTimeoutSeconds,
            refreshOnly: true,
        );

        $env = $this->buildEnv($ctx);
        $outcome = $this->runner->run($planArgv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if ($outcome->exitCode !== 0 && $outcome->exitCode !== 2) {
            throw new IacException(sprintf(
                "terraform plan -refresh-only failed (exit %d): %s",
                $outcome->exitCode,
                $this->redact($outcome->stderr, $ctx),
            ));
        }

        return $this->parsePlan($binary, $ws, $planFile, $ctx);
    }

    public function capabilities(): CapabilityDescriptor
    {
        $version = 'unknown';
        try {
            $version = $this->resolver->version();
        } catch (\Throwable) {
        }

        return CapabilityDescriptor::create(
            [
                IacEngineCapability::RemoteState->value => true,
                IacEngineCapability::StateLocking->value => true,
                IacEngineCapability::PlanFile->value => true,
                IacEngineCapability::Workspaces->value => true,
                IacEngineCapability::JsonOutput->value => true,
                IacEngineCapability::PolicyGate->value => false,
                IacEngineCapability::DirectProvision->value => false,
            ],
            [
                'binary' => $this->resolver->binaryName(),
                'version' => $version,
            ],
        );
    }

    /** @return array<string, string> */
    private function buildEnv(IacExecutionContext $ctx): array
    {
        $env = ['PATH' => '/usr/local/bin:/usr/bin:/bin'];
        $env['TF_IN_AUTOMATION'] = '1';
        $env['TF_INPUT'] = '0';

        foreach ($ctx->envAllowlist as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        foreach ($ctx->providerCredentials as $key => $secret) {
            $env[$key] = $secret->reveal();
        }

        return $env;
    }

    private function redact(string $output, IacExecutionContext $ctx): string
    {
        $secrets = [];
        foreach ($ctx->providerCredentials as $secret) {
            $secrets[] = $secret->reveal();
        }

        return (new SecretRedactor($secrets))->redact($output);
    }

    private function parsePlan(string $binary, IacWorkspace $ws, string $planFile, IacExecutionContext $ctx): IacPlan
    {
        $showArgv = ShowArgv::build($binary, $planFile);
        $env = $this->buildEnv($ctx);
        $showOutcome = $this->runner->run($showArgv, $ws->workingDir, $env, $ctx->timeoutSeconds);

        if (!$showOutcome->isSuccess()) {
            throw new IacException(sprintf(
                "terraform show -json failed (exit %d): %s",
                $showOutcome->exitCode,
                $this->redact($showOutcome->stderr, $ctx),
            ));
        }

        return $this->parser->parse($showOutcome->stdout, $planFile);
    }
}
