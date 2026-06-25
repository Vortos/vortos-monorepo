<?php

declare(strict_types=1);

namespace Vortos\Deploy\Registry\Auth;

use Vortos\Deploy\Execution\CommandRunnerInterface;
use Vortos\Deploy\Registry\RegistryCredential;
use Vortos\OpsKit\Driver\DriverInterface;

/**
 * Port: registry-specific runtime authentication strategy.
 *
 * Each driver knows how to authenticate via docker login to exactly one registry type.
 * Implementations live in Driver\Registry\Auth\ and are collected by the
 * CollectRegistryAuthStrategiesPass at compile time.
 *
 * Security contract:
 *  - credentials are passed via $runner stdin, never as CLI args
 *  - all plaintext tokens are registered via redactTokens() so the runner
 *    scrubs them from every log line
 */
interface RegistryAuthStrategyInterface extends DriverInterface
{
    /**
     * Returns true when this strategy handles the given credential type.
     * Implementations use instanceof — no reflection, no string matching.
     */
    public function supports(RegistryCredential $credential): bool;

    /**
     * Authenticates to the registry using the credential.
     * Passes secrets via stdin; never includes them in argv.
     *
     * @throws \InvalidArgumentException when $credential is not supported
     * @throws \Vortos\Deploy\Exception\CommandFailedException on login failure
     */
    public function login(CommandRunnerInterface $runner, RegistryCredential $credential): void;

    /**
     * Returns the plaintext strings that must be redacted from all log output.
     * Called before and after login() to seed the runner's redaction list.
     *
     * Returns empty when $credential is not supported by this strategy.
     *
     * @return list<string>
     */
    public function redactTokens(RegistryCredential $credential): array;
}
