<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * The Docker Compose 'healthcheck' the blue/green **worker** service runs (GAP-G).
 *
 * In the deploy-in-image model ONE image serves every role, so the worker color inherits the base
 * image's HTTP 'HEALTHCHECK' (FrankenPHP's 'curl :2019/metrics') — but the worker runs supervisord,
 * with nothing on :2019, so the probe can never pass and the container is reported 'unhealthy'
 * forever. Every worker service therefore emits an explicit healthcheck that OVERRIDES the inherited
 * one: a real 'supervisorctl'-based check when the worker runs supervisord (the framework default), or
 * an explicit disable for a custom worker command whose health the framework cannot assume.
 */
final readonly class WorkerHealthcheck
{
    /** Compose healthcheck 'test' forms that run a command (as opposed to 'NONE'). */
    private const COMMAND_FORMS = ['CMD', 'CMD-SHELL'];

    /** Default supervisord config path the framework worker image ships. */
    public const SUPERVISORD_CONFIG = '/etc/supervisord.conf';

    /**
     * @param list<string> $test compose 'test' list, e.g. ['CMD-SHELL', '…']; empty when disabled
     */
    public function __construct(
        public bool $disabled,
        public array $test = [],
        public string $interval = '30s',
        public string $timeout = '5s',
        public int $retries = 3,
        public string $startPeriod = '20s',
    ) {
        if ($disabled) {
            if ($test !== []) {
                throw new \InvalidArgumentException('A disabled WorkerHealthcheck must not carry a test command.');
            }

            return;
        }

        if ($test === []) {
            throw new \InvalidArgumentException('An enabled WorkerHealthcheck must declare a non-empty test command.');
        }

        foreach ($test as $part) {
            if (!is_string($part) || $part === '') {
                throw new \InvalidArgumentException('WorkerHealthcheck.test entries must be non-empty strings.');
            }
        }

        if (!in_array($test[0], self::COMMAND_FORMS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'WorkerHealthcheck.test must start with one of %s, got "%s".',
                implode(' / ', self::COMMAND_FORMS),
                $test[0],
            ));
        }

        foreach (['interval' => $interval, 'timeout' => $timeout, 'startPeriod' => $startPeriod] as $field => $value) {
            if (preg_match('/^\d+(ms|s|m|h)$/', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'WorkerHealthcheck.%s must be a Compose duration (e.g. "30s", "1m"), got "%s".',
                    $field,
                    $value,
                ));
            }
        }

        if ($retries < 1) {
            throw new \InvalidArgumentException(sprintf('WorkerHealthcheck.retries must be >= 1, got %d.', $retries));
        }
    }

    /**
     * Override the inherited HTTP healthcheck with an explicit disable — the correct default for a
     * custom worker command whose liveness the framework cannot assume. Still overrides the base image.
     */
    public static function disabled(): self
    {
        return new self(disabled: true);
    }

    /**
     * A real command healthcheck.
     *
     * @param list<string> $test
     */
    public static function command(
        array $test,
        string $interval = '30s',
        string $timeout = '5s',
        int $retries = 3,
        string $startPeriod = '20s',
    ): self {
        return new self(
            disabled: false,
            test: $test,
            interval: $interval,
            timeout: $timeout,
            retries: $retries,
            startPeriod: $startPeriod,
        );
    }

    /**
     * The enterprise default for a supervisord worker. Healthy ⇔ supervisord is reachable, at least
     * one managed program is RUNNING, and NO program is in a genuinely-failed terminal state
     * (FATAL/BACKOFF/UNKNOWN).
     *
     * This deliberately tolerates transient/benign states so the worker is not reported "unhealthy"
     * forever for the wrong reasons — the failure mode of the previous implementation, which ran
     * supervisorctl TWICE (two racy snapshots) and, via a negated "grep -qvE RUNNING", flagged the whole
     * worker on ANY line that lacked "RUNNING": a trailing blank line, a stderr/warning line, a
     * briefly-STARTING program during boot, or a legitimately-EXITED one-shot. Here supervisorctl runs
     * ONCE, its stdout is inspected for specific tokens, and only FATAL/BACKOFF/UNKNOWN (a program that
     * crashed and cannot recover) marks the container unhealthy. STARTING is covered by start_period +
     * retries; an unreachable supervisord yields empty stdout → no RUNNING match → unhealthy.
     */
    public static function supervisord(string $configPath = self::SUPERVISORD_CONFIG): self
    {
        // Healthy ⇔ at least one program is RUNNING (which also proves supervisord is reachable) AND no
        // program is in a genuinely-failed terminal state (FATAL/BACKOFF/UNKNOWN). Everything else —
        // a briefly-STARTING program, a one-shot that EXITED, blank/warning lines — is tolerated.
        //
        // The test is written WITHOUT shell variables or command substitution ON PURPOSE. The healthcheck
        // string is embedded in the compose file that "docker compose up" reads, and Compose performs its
        // own dollar-VAR interpolation on that file — so an "S=$(...); echo $S" form has its $S eaten by
        // Compose (it expands the undefined var to empty) and the probe silently checks an empty string.
        // Two piped supervisorctl calls avoid any dollar sign; the second snapshot being a few ms apart is
        // benign for a 30s-interval liveness probe. Backticks are likewise avoided (NoShellExec arch gate).
        $status = sprintf('supervisorctl -c %s status 2>/dev/null', $configPath);
        $test = sprintf(
            '%s | grep -qE "\\bRUNNING\\b" && ! %s | grep -qE "\\b(FATAL|BACKOFF|UNKNOWN)\\b"',
            $status,
            $status,
        );

        return self::command(['CMD-SHELL', $test]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->disabled) {
            return ['disable' => true];
        }

        return [
            'test' => $this->test,
            'interval' => $this->interval,
            'timeout' => $this->timeout,
            'retries' => $this->retries,
            'start_period' => $this->startPeriod,
        ];
    }
}
