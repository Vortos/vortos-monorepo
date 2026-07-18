<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runtime;

/**
 * The Docker Compose 'healthcheck' the blue/green **app** service runs — and, crucially, the signal
 * the co-located worker gates on via a depends_on condition of 'service_healthy'.
 *
 * In the deploy-in-image model the app color inherits the base image's HTTP HEALTHCHECK (FrankenPHP's
 * 'curl :2019/metrics'), which flips to 'healthy' the moment the HTTP server binds — well before the
 * application is actually READY to serve (kernel warmed, DB/Redis/broker reachable, caches primed).
 * That is an acceptable *liveness* signal but a useless *readiness* one, and it is exactly why the
 * worker used to co-boot straight into the app's readiness window: 'docker compose up' started the
 * app and the worker together, the worker fanned out its consumers, and on a COLD start that
 * stampede (offset-reset replays + empty-group rebalances against a single broker) saturated the
 * very broker the booting app had to reach — so the app color could not answer /health/ready inside
 * the deploy's readiness-gate window and the cutover aborted.
 *
 * Emitting an explicit readiness healthcheck (curl the canonical /health/ready contract on the
 * container port) makes the app container's health reflect TRUE readiness, so the worker can declare
 * depends_on it with a condition of 'service_healthy' and Compose will hold the worker until the app is
 * genuinely ready. The stampede then happens after the gate has already been satisfied — no race.
 *
 * Mirrors {@see WorkerHealthcheck} (GAP-G) but for the app service; the default is an HTTP readiness
 * probe instead of a supervisorctl check.
 */
final readonly class AppHealthcheck
{
    /** Compose 'test' forms that run a command (as opposed to 'NONE'). */
    private const COMMAND_FORMS = ['CMD', 'CMD-SHELL'];

    /** The canonical, framework-fixed readiness contract served by vortos-health. */
    public const DEFAULT_READINESS_PATH = '/health/ready';

    /**
     * @param list<string> $test compose 'test' list, e.g. ['CMD-SHELL', '…']; empty when disabled
     */
    public function __construct(
        public bool $disabled,
        public array $test = [],
        public string $interval = '3s',
        public string $timeout = '5s',
        public int $retries = 20,
        public string $startPeriod = '10s',
    ) {
        if ($disabled) {
            if ($test !== []) {
                throw new \InvalidArgumentException('A disabled AppHealthcheck must not carry a test command.');
            }

            return;
        }

        if ($test === []) {
            throw new \InvalidArgumentException('An enabled AppHealthcheck must declare a non-empty test command.');
        }

        foreach ($test as $part) {
            if ($part === '') {
                throw new \InvalidArgumentException('AppHealthcheck.test entries must be non-empty strings.');
            }
        }

        if (!in_array($test[0], self::COMMAND_FORMS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'AppHealthcheck.test must start with one of %s, got "%s".',
                implode(' / ', self::COMMAND_FORMS),
                $test[0],
            ));
        }

        foreach (['interval' => $interval, 'timeout' => $timeout, 'startPeriod' => $startPeriod] as $field => $value) {
            if (preg_match('/^\d+(ms|s|m|h)$/', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'AppHealthcheck.%s must be a Compose duration (e.g. "3s", "1m"), got "%s".',
                    $field,
                    $value,
                ));
            }
        }

        if ($retries < 1) {
            throw new \InvalidArgumentException(sprintf('AppHealthcheck.retries must be >= 1, got %d.', $retries));
        }
    }

    /**
     * Override the inherited HTTP healthcheck with an explicit disable — the correct choice for a
     * custom, non-HTTP app whose readiness the framework cannot assume. With no readiness signal the
     * worker cannot gate on the app, so {@see ComposeFile} falls back to the prior co-boot behaviour.
     */
    public static function disabled(): self
    {
        return new self(disabled: true);
    }

    /**
     * A bespoke command healthcheck.
     *
     * @param list<string> $test
     */
    public static function command(
        array $test,
        string $interval = '3s',
        string $timeout = '5s',
        int $retries = 20,
        string $startPeriod = '10s',
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
     * The enterprise default: healthy only when the canonical readiness endpoint answers 2xx. Uses
     * curl (present in the framework app image — it is the tool the inherited HTTP healthcheck already
     * used) against the LOOPBACK container port, so the probe is independent of the edge and of DNS.
     *
     * Defaults give a ~70s window before the container is marked unhealthy (10s start-period + 20×3s),
     * comfortably wider than the deploy readiness gate, so nothing that would pass the gate fails this.
     */
    public static function httpReadiness(
        int $port,
        string $path = self::DEFAULT_READINESS_PATH,
        string $interval = '3s',
        string $timeout = '5s',
        int $retries = 20,
        string $startPeriod = '10s',
    ): self {
        $test = sprintf('curl -fsS -o /dev/null http://127.0.0.1:%d%s || exit 1', $port, $path);

        return self::command(['CMD-SHELL', $test], $interval, $timeout, $retries, $startPeriod);
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
