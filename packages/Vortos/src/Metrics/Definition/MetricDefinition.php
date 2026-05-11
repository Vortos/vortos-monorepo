<?php

declare(strict_types=1);

namespace Vortos\Metrics\Definition;

final readonly class MetricDefinition
{
    /**
     * @param list<string> $labelNames
     * @param list<float|int> $buckets
     */
    private function __construct(
        public MetricType $type,
        public string $name,
        public string $help,
        public array $labelNames = [],
        public array $buckets = [],
    ) {
        self::assertValidName($name);
        self::assertValidHelp($help);
        self::assertValidLabelNames($labelNames);

        if ($type === MetricType::Histogram) {
            self::assertValidBuckets($buckets);
        } elseif ($buckets !== []) {
            throw new \InvalidArgumentException('Only histogram metrics may declare buckets.');
        }
    }

    /**
     * @param list<string> $labelNames
     */
    public static function counter(string $name, string $help, array $labelNames = []): self
    {
        return new self(MetricType::Counter, $name, $help, array_values($labelNames));
    }

    /**
     * @param list<string> $labelNames
     */
    public static function gauge(string $name, string $help, array $labelNames = []): self
    {
        return new self(MetricType::Gauge, $name, $help, array_values($labelNames));
    }

    /**
     * @param list<string> $labelNames
     * @param list<float|int> $buckets
     */
    public static function histogram(string $name, string $help, array $labelNames = [], array $buckets = []): self
    {
        return new self(MetricType::Histogram, $name, $help, array_values($labelNames), array_values($buckets));
    }

    /**
     * @param array{type: string, name: string, help: string, label_names?: list<string>, buckets?: list<float|int>} $data
     */
    public static function fromArray(array $data): self
    {
        $type = MetricType::from($data['type']);

        return match ($type) {
            MetricType::Counter => self::counter($data['name'], $data['help'], $data['label_names'] ?? []),
            MetricType::Gauge => self::gauge($data['name'], $data['help'], $data['label_names'] ?? []),
            MetricType::Histogram => self::histogram(
                $data['name'],
                $data['help'],
                $data['label_names'] ?? [],
                $data['buckets'] ?? [],
            ),
        };
    }

    /**
     * @return array{type: string, name: string, help: string, label_names: list<string>, buckets: list<float|int>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'name' => $this->name,
            'help' => $this->help,
            'label_names' => $this->labelNames,
            'buckets' => $this->buckets,
        ];
    }

    private static function assertValidName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid metric name "%s".', $name));
        }
    }

    private static function assertValidHelp(string $help): void
    {
        if (trim($help) === '') {
            throw new \InvalidArgumentException('Metric help text must not be empty.');
        }

        if (preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $help)) {
            throw new \InvalidArgumentException('Metric help text must not contain control characters.');
        }
    }

    /**
     * @param list<string> $labelNames
     */
    private static function assertValidLabelNames(array $labelNames): void
    {
        $seen = [];
        foreach ($labelNames as $labelName) {
            if (!is_string($labelName) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $labelName)) {
                throw new \InvalidArgumentException(sprintf('Invalid metric label name "%s".', (string) $labelName));
            }

            if (str_starts_with($labelName, '__')) {
                throw new \InvalidArgumentException(sprintf('Metric label name "%s" is reserved.', $labelName));
            }

            if (isset($seen[$labelName])) {
                throw new \InvalidArgumentException(sprintf('Duplicate metric label name "%s".', $labelName));
            }

            $seen[$labelName] = true;
        }
    }

    /**
     * @param list<float|int> $buckets
     */
    private static function assertValidBuckets(array $buckets): void
    {
        if ($buckets === []) {
            throw new \InvalidArgumentException('Histogram metrics must declare buckets.');
        }

        $previous = null;
        foreach ($buckets as $bucket) {
            if (!is_int($bucket) && !is_float($bucket)) {
                throw new \InvalidArgumentException('Histogram buckets must be numeric.');
            }

            $bucket = (float) $bucket;
            if ($bucket <= 0.0 || is_infinite($bucket) || is_nan($bucket)) {
                throw new \InvalidArgumentException('Histogram buckets must be finite positive numbers.');
            }

            if ($previous !== null && $bucket <= $previous) {
                throw new \InvalidArgumentException('Histogram buckets must be strictly increasing.');
            }

            $previous = $bucket;
        }
    }
}
