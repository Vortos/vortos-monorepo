<?php

declare(strict_types=1);

namespace Vortos\Iac\Driver\Terraform;

use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;

/**
 * Runs terraform through the framework launcher with EXACTLY the environment the engine built: the
 * engine already assembles an allowlist (PATH, TF_* and declared keys), so the parent environment is
 * not inherited. Provider credentials stay SecretValues until the child's environment is written, and
 * are scrubbed from everything terraform prints.
 */
final class SystemProcessRunner implements ProcessRunnerInterface
{
    private const MAX_OUTPUT_BYTES = 1_048_576; // 1 MiB

    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    public function run(array $argv, string $cwd, array $env, int $timeoutSeconds): ProcessOutcome
    {
        $result = $this->launcher->run(new ProcessSpec(
            $argv,
            EnvironmentPolicy::Minimal,
            (float) $timeoutSeconds,
            env: $env,
            cwd: $cwd,
            maxOutputBytes: self::MAX_OUTPUT_BYTES,
        ));

        return new ProcessOutcome(
            $result->timedOut ? 124 : $result->exitCode,
            $result->stdout,
            $result->timedOut ? 'terraform timed out after ' . $timeoutSeconds . 's' . ($result->stderr !== '' ? ': ' . $result->stderr : '') : $result->stderr,
            $result->durationMs,
        );
    }
}
