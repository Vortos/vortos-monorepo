<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use InvalidArgumentException;

/**
 * A tiny, deterministic YAML emitter for the constrained shapes the collector config
 * uses (nested string-keyed maps, integer-keyed lists, and scalar leaves).
 *
 * Deliberately NOT a general YAML library — the package stays dependency-free (§14.2)
 * and the output is byte-stable so a contract test can pin it. Anything outside the
 * supported shape (objects, resources, mixed-key arrays) is rejected loudly rather
 * than emitted ambiguously.
 */
final class YamlWriter
{
    /**
     * @param array<string, mixed> $data
     */
    public function dump(array $data): string
    {
        return "---\n" . $this->dumpMap($data, 0);
    }

    /**
     * @param array<array-key, mixed> $map
     */
    private function dumpMap(array $map, int $indent): string
    {
        if ($map === []) {
            return str_repeat('  ', $indent) . "{}\n";
        }

        $out = '';
        $pad = str_repeat('  ', $indent);
        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('YamlWriter map keys must be strings.');
            }
            $out .= $pad . $this->scalarKey($key) . ':';
            if (is_array($value)) {
                if ($this->isList($value)) {
                    $out .= "\n" . $this->dumpList($value, $indent + 1);
                } elseif ($value === []) {
                    $out .= " {}\n";
                } else {
                    /** @var array<string, mixed> $value */
                    $out .= "\n" . $this->dumpMap($value, $indent + 1);
                }
            } else {
                $out .= ' ' . $this->scalar($value) . "\n";
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $list
     */
    private function dumpList(array $list, int $indent): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);
        foreach ($list as $item) {
            if (is_array($item)) {
                if ($this->isList($item)) {
                    // Inline nested list for readability of simple sequences.
                    $out .= $pad . '- ' . $this->inlineList($item) . "\n";
                } else {
                    /** @var array<string, mixed> $item */
                    $nested = $this->dumpMap($item, $indent + 1);
                    // Hoist the first line onto the dash.
                    $nested = ltrim($nested);
                    $out .= $pad . '- ' . $nested;
                }
            } else {
                $out .= $pad . '- ' . $this->scalar($item) . "\n";
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $list
     */
    private function inlineList(array $list): string
    {
        $parts = array_map(fn ($v): string => $this->scalar($v), $list);

        return '[' . implode(', ', $parts) . ']';
    }

    private function scalarKey(string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_.\/-]+$/', $key) === 1) {
            return $key;
        }

        return '"' . str_replace('"', '\"', $key) . '"';
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('YamlWriter only emits scalar leaves, got ' . get_debug_type($value) . '.');
        }

        // Quote when the bareword could be misread (env placeholders, special chars, empties).
        if ($value === '' || preg_match('/^[A-Za-z0-9_.\/:${}-]+$/', $value) !== 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\"'], $value) . '"';
        }

        // A bareword string that would parse back as a number, bool, or null MUST be quoted to
        // preserve its string type — e.g. docker_stats `api_version: "1.44"` must not become a float.
        if (is_numeric($value) || in_array(strtolower($value), ['true', 'false', 'null', 'yes', 'no', 'on', 'off', '~'], true)) {
            return '"' . $value . '"';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function isList(array $value): bool
    {
        return $value === [] ? false : array_is_list($value);
    }
}
