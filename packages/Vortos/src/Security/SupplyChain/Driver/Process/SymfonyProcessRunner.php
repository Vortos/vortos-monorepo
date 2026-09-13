<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Process;

use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;
use Vortos\Foundation\Secret\SecretValue;

/**
 * Runs supply-chain tools (cosign, syft, trivy) through the framework launcher.
 *
 * Environment is inherited on purpose: these tools resolve registry credentials, OIDC tokens and
 * docker configuration from the ambient environment (e.g. ACTIONS_ID_TOKEN_REQUEST_TOKEN, DOCKER_CONFIG),
 * and the set is open-ended per provider. Secret values passed in `$env` are redacted from output.
 */
final class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    public function run(array $command, array $env = [], ?int $timeoutSeconds = null): ProcessOutput
    {
        if ($command === []) {
            throw ProcessFailedException::timeout('unknown', 0);
        }

        $result = $this->launcher->run(new ProcessSpec(
            $command,
            EnvironmentPolicy::Inherit,
            $timeoutSeconds !== null ? (float) $timeoutSeconds : null,
            env: $env,
            maxOutputBytes: 64 * 1_048_576,
        ));

        if ($result->timedOut) {
            throw ProcessFailedException::timeout($command[0], $timeoutSeconds ?? 0);
        }

        return new ProcessOutput(
            exitCode: $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->stderr,
        );
    }
}
