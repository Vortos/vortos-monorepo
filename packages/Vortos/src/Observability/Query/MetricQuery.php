<?php

declare(strict_types=1);

namespace Vortos\Observability\Query;

/**
 * A structured, injection-safe metrics query.
 *
 * Label matchers are validated (allowlisted names) and escaped before being
 * serialised into PromQL — raw caller-controlled strings are NEVER concatenated
 * directly into the query expression (PromQL injection guard §4.1).
 */
final readonly class MetricQuery
{
    private const LABEL_NAME_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @param string $indicatorRef  The base PromQL metric selector (e.g. "http_requests_total")
     * @param array<string,string> $labelMatchers  Structured label name→value pairs
     */
    public function __construct(
        public string $indicatorRef,
        public array $labelMatchers,
    ) {
        if ($indicatorRef === '') {
            throw new \InvalidArgumentException('MetricQuery indicatorRef must not be empty.');
        }
        foreach (array_keys($labelMatchers) as $name) {
            if (!preg_match(self::LABEL_NAME_PATTERN, $name)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid label name "%s": must match %s.',
                    $name,
                    self::LABEL_NAME_PATTERN,
                ));
            }
        }
    }

    /** @param array<string,string> $labelMatchers */
    public static function fromSloRef(string $indicatorRef, array $labelMatchers = []): self
    {
        return new self($indicatorRef, $labelMatchers);
    }

    /**
     * Builds a safe PromQL selector by appending escaped label matchers.
     *
     * Output: `metric_name{label1="value1",label2="value2"}` or just `metric_name`
     * if no matchers are given.
     */
    public function toPromQL(): string
    {
        if ($this->labelMatchers === []) {
            return $this->indicatorRef;
        }

        $parts = [];
        foreach ($this->labelMatchers as $name => $value) {
            $parts[] = sprintf('%s="%s"', $name, $this->escapeLabelValue($value));
        }

        return sprintf('%s{%s}', $this->indicatorRef, implode(',', $parts));
    }

    private function escapeLabelValue(string $value): string
    {
        // Escape backslashes first, then double-quotes, then newlines
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);
        $value = str_replace("\n", '\\n', $value);
        $value = str_replace("\r", '\\r', $value);

        return $value;
    }
}
