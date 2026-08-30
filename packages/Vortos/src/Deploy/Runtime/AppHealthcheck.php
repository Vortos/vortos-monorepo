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
     * Steady-state probe cadence, once the container has reported healthy at least once.
     *
     * This was 3s, chosen so the worker's a depends_on condition of 'service_healthy' gate would flip quickly at
     * boot. It did — and then kept firing every 3 seconds for the life of the container, forever, on
     * an idle system. A readiness probe is not free: it occupies a request-handling thread for its
     * whole duration (the app tier's real concurrency is a fixed worker-thread count), and every
     * dependency it touches is exercised at that cadence, which on a metered provider is a line item.
     *
     * 30s sits in the normal band for a steady-state probe — Kubernetes' periodSeconds defaults to
     * 10 and 10-30s is the usual range. Fast BOOT detection is preserved by {@see $startInterval}
     * rather than by punishing the entire steady state for it.
     */
    public const DEFAULT_INTERVAL = '30s';

    /**
     * Probe cadence DURING the start period — Compose start_interval, which needs Docker Engine 25+
     * (API 1.44+). This is what makes a slow steady-state interval safe: the container is still
     * polled every 3s while it boots, so service_healthy flips as promptly as it ever did and the
     * deploy's readiness gate is unaffected.
     */
    public const DEFAULT_START_INTERVAL = '3s';

    /**
     * Consecutive failures before a HEALTHY container is declared unhealthy.
     *
     * Reduced from 20 alongside the interval change, because retries multiply it: 20 x 3s was a ~60s
     * detection window, while 20 x 30s would have been ten minutes. 5 x 30s restores a ~2.5 minute
     * window. Failures inside the start period do not count toward this, so boot is unaffected.
     */
    public const DEFAULT_RETRIES = 5;

    /**
     * @param list<string> $test compose 'test' list, e.g. ['CMD-SHELL', '…']; empty when disabled
     */
    public function __construct(
        public bool $disabled,
        public array $test = [],
        public string $interval = self::DEFAULT_INTERVAL,
        public string $timeout = '5s',
        public int $retries = self::DEFAULT_RETRIES,
        public string $startPeriod = '10s',
        public string $startInterval = self::DEFAULT_START_INTERVAL,
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

        foreach ([
            'interval'      => $interval,
            'timeout'       => $timeout,
            'startPeriod'   => $startPeriod,
            'startInterval' => $startInterval,
        ] as $field => $value) {
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
        string $interval = self::DEFAULT_INTERVAL,
        string $timeout = '5s',
        int $retries = self::DEFAULT_RETRIES,
        string $startPeriod = '10s',
        string $startInterval = self::DEFAULT_START_INTERVAL,
    ): self {
        return new self(
            disabled: false,
            test: $test,
            interval: $interval,
            timeout: $timeout,
            retries: $retries,
            startPeriod: $startPeriod,
            startInterval: $startInterval,
        );
    }

    /**
     * The enterprise default: healthy only when the canonical readiness endpoint answers 2xx. Uses
     * curl (present in the framework app image — it is the tool the inherited HTTP healthcheck already
     * used) against the LOOPBACK container port, so the probe is independent of the edge and of DNS.
     *
     * Defaults poll every 3s while booting (10s start-period, {@see DEFAULT_START_INTERVAL}) so the
     * worker's service_healthy gate flips promptly, then settle to 30s with 5 retries — a ~2.5 minute
     * window before a running container is marked unhealthy. Nothing that would pass the deploy's
     * readiness gate fails this.
     */
    public static function httpReadiness(
        int $port,
        string $path = self::DEFAULT_READINESS_PATH,
        string $interval = self::DEFAULT_INTERVAL,
        string $timeout = '5s',
        int $retries = self::DEFAULT_RETRIES,
        string $startPeriod = '10s',
        string $startInterval = self::DEFAULT_START_INTERVAL,
    ): self {
        $test = sprintf('curl -fsS -o /dev/null http://127.0.0.1:%d%s || exit 1', $port, $path);

        return self::command(['CMD-SHELL', $test], $interval, $timeout, $retries, $startPeriod, $startInterval);
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
            'start_interval' => $this->startInterval,
        ];
    }
}
