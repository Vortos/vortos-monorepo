<?php

declare(strict_types=1);

namespace Vortos\Deploy\Execution;

use Vortos\Foundation\Process\EnvironmentPolicy;
use Vortos\Foundation\Process\ProcessLauncher;
use Vortos\Foundation\Process\ProcessLauncherInterface;
use Vortos\Foundation\Process\ProcessSpec;

/**
 * Deploy's command runner, over the framework launcher.
 *
 * Environment is inherited deliberately: docker, ssh and scp take their endpoint and agent from the
 * ambient environment (DOCKER_HOST, SSH_AUTH_SOCK) and the deploy one-shot is provisioned for exactly
 * that. Every redact token is declared to the launcher, so it is refused on argv and scrubbed from
 * output before this result exists — CommandResult's own redaction is a second layer, not the only one.
 */
final class ProcessCommandRunner implements CommandRunnerInterface
{
    private const DEFAULT_TIMEOUT = 300.0;

    private const MAX_OUTPUT_BYTES = 64 * 1_048_576;

    public function __construct(
        private readonly ProcessLauncherInterface $launcher = new ProcessLauncher(),
    ) {}

    public function run(array $argv, string|\Vortos\Foundation\Secret\SecretValue|null $stdin = null, ?float $timeout = null, array $redactTokens = []): CommandResult
    {
        if ($argv === []) {
            throw new \InvalidArgumentException('argv must not be empty.');
        }

        $result = $this->launcher->run(
            new ProcessSpec(
                $argv,
                EnvironmentPolicy::Inherit,
                $timeout ?? self::DEFAULT_TIMEOUT,
                maxOutputBytes: self::MAX_OUTPUT_BYTES,
                redact: $redactTokens,
            ),
            $stdin,
        );

        return new CommandResult(
            exitCode: $result->timedOut ? 124 : $result->exitCode,
            stdout: $result->stdout,
            stderr: $result->timedOut ? trim('timed out after ' . ($timeout ?? self::DEFAULT_TIMEOUT) . 's. ' . $result->stderr) : $result->stderr,
            duration: $result->durationMs / 1000,
            redactTokens: $redactTokens,
        );
    }
}
