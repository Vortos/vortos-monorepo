<?php

declare(strict_types=1);

namespace Vortos\Metrics\DependencyInjection;

use Vortos\Metrics\Config\MetricsAdapter;
use Vortos\Metrics\Config\MetricsModule;
use Vortos\Metrics\Definition\MetricDefinition;

/**
 * Fluent configuration object for vortos-metrics.
 *
 * Loaded via require in MetricsExtension::load().
 * Every setting has a sensible default — no config file is required for basic usage.
 *
 * ## Standard usage
 *
 * Create config/metrics.php in your project:
 *
 *   return static function (VortosMetricsConfig $config): void {
 *       $config
 *           ->adapter(MetricsAdapter::Prometheus)
 *           ->prometheusStorageRedis(prefix: 'metrics:')
 *           ->prometheusEndpoint('/metrics')
 *           ->prometheusEndpointToken($_ENV['METRICS_TOKEN'] ?? '');
 *   };
 *
 * ## Multi-process storage (FrankenPHP)
 *
 * FrankenPHP runs multiple PHP workers — Prometheus in-memory storage is
 * process-local and cannot aggregate across workers. Use Redis storage in production:
 *
 *   $config->prometheusStorageRedis(prefix: 'metrics:');
 *
 * Requires vortos/vortos-cache with the Redis driver active.
 *
 * ## Default adapter
 *
 * Default is NoOp — zero overhead, no configuration required.
 * Switch to Prometheus or StatsD when you need actual metrics.
 *
 * ## Application metrics
 *
 * Use counter(), gauge(), or histogram() to declare application-owned metrics.
 * Runtime calls through MetricsInterface must use a declared name and exactly
 * the declared labels. Histogram buckets are configured here, not per call.
 */
final class VortosMetricsConfig
{
    private MetricsAdapter $adapter = MetricsAdapter::NoOp;
    private string $namespace = 'vortos';

    /** @var list<MetricsModule> */
    private array $disabledModules = [];

    /** @var list<MetricDefinition> */
    private array $metricDefinitions = [];

    // Prometheus-specific
    private string $prometheusStorage = 'memory';
    private string $prometheusRedisPrefix = 'metrics:';
    private string $prometheusRedisHost = '127.0.0.1';
    private int $prometheusRedisPort = 6379;
    private string $prometheusRedisPassword = '';
    private string $prometheusEndpointPath = '/metrics';
    private string $prometheusEndpointToken = '';
    private bool $prometheusEndpointOpenAccess = false;

    // StatsD-specific
    private string $statsDHost = '127.0.0.1';
    private int $statsDPort = 8125;
    private float $statsDSampleRate = 1.0;

    public function adapter(MetricsAdapter $adapter): static
    {
        $this->adapter = $adapter;
        return $this;
    }

    /**
     * Set the metric namespace prefix.
     *
     * All metric names are prefixed: {namespace}_{name}
     * Default: 'vortos'
     */
    public function namespace(string $ns): static
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $ns)) {
            throw new \InvalidArgumentException(sprintf('Invalid metrics namespace "%s".', $ns));
        }

        $this->namespace = $ns;
        return $this;
    }

    /**
     * Disable auto-instrumentation for specific framework modules.
     *
     * Useful when you want to replace a framework module metric with custom
     * lower-cardinality instrumentation.
     */
    public function disableModule(MetricsModule ...$modules): static
    {
        foreach ($modules as $module) {
            $this->disabledModules[] = $module;
        }
        return $this;
    }

    /**
     * Register an application-owned counter metric.
     *
     * Labels are names only. Values are provided at observation time and must
     * exactly match this list.
     *
     * @param list<string> $labelNames
     */
    public function counter(string $name, string $help, array $labelNames = []): static
    {
        return $this->metric(MetricDefinition::counter($name, $help, $labelNames));
    }

    /**
     * Register an application-owned gauge metric.
     *
     * @param list<string> $labelNames
     */
    public function gauge(string $name, string $help, array $labelNames = []): static
    {
        return $this->metric(MetricDefinition::gauge($name, $help, $labelNames));
    }

    /**
     * Register an application-owned histogram metric.
     *
     * @param list<string> $labelNames
     * @param list<float|int> $buckets
     */
    public function histogram(string $name, string $help, array $labelNames, array $buckets): static
    {
        return $this->metric(MetricDefinition::histogram($name, $help, $labelNames, $buckets));
    }

    public function metric(MetricDefinition $definition): static
    {
        $this->metricDefinitions[] = $definition;
        return $this;
    }

    /**
     * Use in-memory Prometheus storage (default).
     * Only safe for single-worker setups (dev). NOT safe for FrankenPHP worker mode.
     */
    public function prometheusStorageInMemory(): static
    {
        $this->prometheusStorage = 'memory';
        return $this;
    }

    /**
     * Use Redis-backed Prometheus storage (multi-process safe).
     * Required for FrankenPHP worker mode in production.
     *
     * If vortos/vortos-cache with Redis driver is active, the existing \Redis connection
     * is reused. Otherwise the metrics package opens its own connection using host/port/password.
     */
    public function prometheusStorageRedis(
        string $prefix = 'metrics:',
        string $host = '127.0.0.1',
        int $port = 6379,
        string $password = '',
    ): static {
        $this->prometheusStorage       = 'redis';
        $this->prometheusRedisPrefix   = $prefix;
        $this->prometheusRedisHost     = $host;
        $this->prometheusRedisPort     = $port;
        $this->prometheusRedisPassword = $password;
        return $this;
    }

    /**
     * Use APC shared memory for Prometheus storage.
     * Multi-process safe with PHP-FPM. Requires apcu extension.
     */
    public function prometheusStorageApc(): static
    {
        $this->prometheusStorage = 'apc';
        return $this;
    }

    /**
     * Configure the Prometheus scrape endpoint path.
     * Only registered when adapter = Prometheus.
     * Default: /metrics
     */
    public function prometheusEndpoint(string $path = '/metrics'): static
    {
        $this->prometheusEndpointPath = $path;
        return $this;
    }

    /**
     * Protect the /metrics endpoint with a Bearer token.
     * In production, always configure a token. MetricsExtension will throw at
     * container compile time if neither a token nor openAccess() is set in prod.
     */
    public function prometheusEndpointToken(string $token): static
    {
        $this->prometheusEndpointToken = $token;
        return $this;
    }

    /**
     * Explicitly opt in to an unauthenticated /metrics endpoint in production.
     *
     * Only call this when the endpoint is protected at the network level
     * (firewall, reverse proxy IP allowlist). Omitting this AND omitting
     * prometheusEndpointToken() causes MetricsExtension to throw in prod.
     */
    public function prometheusEndpointOpenAccess(): static
    {
        $this->prometheusEndpointOpenAccess = true;
        return $this;
    }

    public function statsDHost(string $host): static
    {
        $this->statsDHost = $host;
        return $this;
    }

    public function statsDPort(int $port): static
    {
        $this->statsDPort = $port;
        return $this;
    }

    public function statsDSampleRate(float $rate): static
    {
        $this->statsDSampleRate = max(0.0, min(1.0, $rate));
        return $this;
    }

    /** @internal Used by MetricsExtension */
    public function toArray(): array
    {
        return [
            'adapter'                    => $this->adapter,
            'namespace'                  => $this->namespace,
            'disabled_modules'           => $this->disabledModules,
            'metric_definitions'         => $this->metricDefinitions,
            'prometheus_storage'         => $this->prometheusStorage,
            'prometheus_redis_prefix'    => $this->prometheusRedisPrefix,
            'prometheus_redis_host'      => $this->prometheusRedisHost,
            'prometheus_redis_port'      => $this->prometheusRedisPort,
            'prometheus_redis_password'  => $this->prometheusRedisPassword,
            'prometheus_endpoint_path'        => $this->prometheusEndpointPath,
            'prometheus_endpoint_token'       => $this->prometheusEndpointToken,
            'prometheus_endpoint_open_access' => $this->prometheusEndpointOpenAccess,
            'statsd_host'                => $this->statsDHost,
            'statsd_port'                => $this->statsDPort,
            'statsd_sample_rate'         => $this->statsDSampleRate,
        ];
    }
}
