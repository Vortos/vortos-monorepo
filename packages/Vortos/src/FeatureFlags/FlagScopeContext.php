<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The ambient (project, environment) scope for the current request / unit of work
 * (Block 10).
 *
 * One shared instance per process. Implements {@see ResetInterface} so it is cleared
 * to its defaults at the end of every request (critical under FrankenPHP / Kafka worker
 * mode where the container is reused across requests).
 *
 * ## How it gets populated
 *
 *   - HTTP: set from the authenticated SDK key or a trusted gateway header after auth.
 *   - CLI:  `--env` option on each command calls `withEnvironment()`.
 *   - Workers/consumers: {@see self::runAs()} with the scope from the message envelope.
 *
 * ## Security — scope is a trust boundary, NEVER client input
 *
 * `environment` is as privileged as `tenantId`. It must never be read from
 * `X-Vortos-Flag-Context` or any attacker-controlled header. A dev key physically
 * cannot see prod state; a prod key cannot pollute dev state. The HTTP layer (later
 * Block 13 SDK keys) is responsible for setting this from the *authenticated identity*,
 * not from the request body or query string.
 *
 * Evaluation outside an explicit scope defaults to `production` — the safest default
 * (most flags are conservative in production).
 */
final class FlagScopeContext implements ResetInterface
{
    public const ENV_PRODUCTION = 'production';

    private string $environment = self::ENV_PRODUCTION;

    public function environment(): string
    {
        return $this->environment;
    }

    /**
     * Set the active environment — call this from the HTTP middleware or CLI option
     * handler. Must only be called with a server-derived value (never client input).
     *
     * @throws \InvalidArgumentException if the environment name is blank or > 64 chars
     */
    public function withEnvironment(string $environment): void
    {
        $environment = trim($environment);

        if ($environment === '' || strlen($environment) > 64) {
            throw new \InvalidArgumentException(
                sprintf('Invalid environment name "%s" — must be 1–64 non-blank characters.', $environment),
            );
        }

        $this->environment = $environment;
    }

    /**
     * Run a callable in a specific environment scope, then restore the previous scope.
     * Safe for nested calls (outer scope is always restored).
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runAs(string $environment, callable $work): mixed
    {
        $previous = $this->environment;
        $this->withEnvironment($environment);

        try {
            return $work();
        } finally {
            $this->environment = $previous;
        }
    }

    /** Reset to production default between requests (worker-mode safety). */
    public function reset(): void
    {
        $this->environment = self::ENV_PRODUCTION;
    }
}
