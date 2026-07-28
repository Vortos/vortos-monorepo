<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

/**
 * The prefix every emitted metric name carries, and the one place that knows how it is applied.
 *
 * This exists because the rule used to live in three adapters and nowhere else. Each adapter
 * prefixed names correctly for its own backend, so emission was right — but anything that had to
 * *predict* a metric name, rather than emit one, had no way to ask. {@see FrameworkDashboardCatalog}
 * built queries straight from {@see FrameworkMetric} values and so generated dashboards that matched
 * nothing on any deployment configuring a namespace other than the default. The JSON was
 * well-formed, the names looked right, and the failure surfaced as an empty panel during an
 * incident.
 *
 * Generator and emitter now share this type, so the two cannot drift: a change to the rule changes
 * both, and a namespace that cannot be represented here cannot be configured either.
 *
 * The separator is per-backend, not per-namespace. Prometheus and OTLP join with '_' because that
 * is what the exposition format and the OTLP→Prometheus translation expect; StatsD joins with '.'
 * because its hierarchy is dot-delimited. That is why {@see prefix()} takes the separator rather
 * than assuming one.
 */
final readonly class MetricNamespace
{
    public const DEFAULT = 'vortos';

    /**
     * Prometheus/OpenTelemetry metric names are [a-zA-Z_][a-zA-Z0-9_]*, so the namespace has to be
     * a legal name on its own — anything else produces a series no query can address.
     */
    private const PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid metrics namespace "%s".', $value));
        }

        return new self($value);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }

    /**
     * Render the fully-qualified name a backend will receive for $name.
     */
    public function prefix(string $name, string $separator = '_'): string
    {
        return $this->value . $separator . $name;
    }

    /**
     * Render the fully-qualified name for a framework metric.
     *
     * Anything predicting a metric name — dashboards, alert rules, runbooks — must go through here
     * rather than reading FrameworkMetric::value, which is the *undecorated* name.
     */
    public function forMetric(FrameworkMetric $metric, string $separator = '_'): string
    {
        return $this->prefix($metric->value, $separator);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
