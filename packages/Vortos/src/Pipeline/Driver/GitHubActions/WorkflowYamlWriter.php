<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Driver\GitHubActions;

use InvalidArgumentException;
use Vortos\Pipeline\Driver\GitHubActions\Yaml\CommentedScalar;

final class WorkflowYamlWriter
{
    /** @param array<string, mixed> $data */
    public function dump(array $data): string
    {
        return $this->dumpMap($data, 0);
    }

    /** @param array<string, mixed> $map */
    private function dumpMap(array $map, int $indent): string
    {
        if ($map === []) {
            return str_repeat('  ', $indent) . "{}\n";
        }

        $out = '';
        $pad = str_repeat('  ', $indent);

        foreach ($map as $key => $value) {
            $out .= $pad . $this->formatKey((string) $key) . ':';

            if (is_array($value)) {
                if ($this->isList($value)) {
                    if ($this->isListOfScalars($value)) {
                        $out .= "\n" . $this->dumpScalarList($value, $indent + 1);
                    } elseif ($this->isListOfMaps($value)) {
                        $out .= "\n" . $this->dumpListOfMaps($value, $indent + 1);
                    } else {
                        $out .= "\n" . $this->dumpMixedList($value, $indent + 1);
                    }
                } elseif ($value === []) {
                    $out .= " {}\n";
                } else {
                    $out .= "\n" . $this->dumpMap($value, $indent + 1);
                }
            } elseif ($value instanceof CommentedScalar) {
                $out .= ' ' . $this->formatCommentedScalar($value) . "\n";
            } elseif (is_string($value) && str_contains($value, "\n")) {
                $out .= " |\n" . $this->dumpBlockScalar($value, $indent + 1);
            } else {
                $out .= ' ' . $this->formatScalar($value) . "\n";
            }
        }

        return $out;
    }

    /** @param list<scalar> $list */
    private function dumpScalarList(array $list, int $indent): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);

        foreach ($list as $item) {
            $out .= $pad . '- ' . $this->formatScalar($item) . "\n";
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $list */
    private function dumpListOfMaps(array $list, int $indent): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);

        foreach ($list as $item) {
            $first = true;
            foreach ($item as $k => $v) {
                if ($first) {
                    $out .= $pad . '- ' . $this->formatKey((string) $k) . ':';
                    $first = false;
                } else {
                    $out .= $pad . '  ' . $this->formatKey((string) $k) . ':';
                }

                if (is_array($v)) {
                    if ($v === []) {
                        $out .= " {}\n";
                    } elseif ($this->isList($v)) {
                        $out .= "\n" . $this->dumpScalarList($v, $indent + 2);
                    } else {
                        $out .= "\n" . $this->dumpMap($v, $indent + 2);
                    }
                } elseif ($v instanceof CommentedScalar) {
                    $out .= ' ' . $this->formatCommentedScalar($v) . "\n";
                } elseif (is_string($v) && str_contains($v, "\n")) {
                    $out .= " |\n" . $this->dumpBlockScalar($v, $indent + 2);
                } else {
                    $out .= ' ' . $this->formatScalar($v) . "\n";
                }
            }
        }

        return $out;
    }

    private function formatCommentedScalar(CommentedScalar $scalar): string
    {
        // The comment is emitted OUTSIDE the scalar as a genuine YAML comment, so a pinned `uses:`
        // resolves to the SHA ref and `# v4` is a real comment — never folded into the value (which
        // would make GitHub resolve a nonexistent `<sha> # v4` ref).
        return $this->formatPlainOrQuoted($scalar->value) . '  # ' . $scalar->comment;
    }

    /**
     * A pinned action ref (`owner/repo@<sha>`) is plain-safe in YAML: the only reserved indicators
     * it contains (`@`) are forbidden only as the FIRST character, and the value starts with an
     * alphanumeric. Emit such tokens plain so the ref stays clean; anything else falls back to the
     * conservative {@see formatScalar} quoting.
     */
    private function formatPlainOrQuoted(string $value): string
    {
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/@-]*$#', $value) === 1) {
            return $value;
        }

        return $this->formatScalar($value);
    }

    /** @param list<mixed> $list */
    private function dumpMixedList(array $list, int $indent): string
    {
        $out = '';
        $pad = str_repeat('  ', $indent);

        foreach ($list as $item) {
            if (is_array($item)) {
                if ($this->isList($item)) {
                    $out .= $pad . '- ' . $this->inlineList($item) . "\n";
                } else {
                    $nested = $this->dumpMap($item, $indent + 1);
                    $nested = ltrim($nested);
                    $out .= $pad . '- ' . $nested;
                }
            } else {
                $out .= $pad . '- ' . $this->formatScalar($item) . "\n";
            }
        }

        return $out;
    }

    private function dumpBlockScalar(string $value, int $indent): string
    {
        $pad = str_repeat('  ', $indent);
        $lines = explode("\n", $value);
        $out = '';

        foreach ($lines as $line) {
            $out .= $pad . $line . "\n";
        }

        return $out;
    }

    /** @param list<mixed> $list */
    private function inlineList(array $list): string
    {
        $parts = array_map(fn ($v): string => $this->formatScalar($v), $list);

        return '[' . implode(', ', $parts) . ']';
    }

    private function formatKey(string $key): string
    {
        if ($key === 'on' || $key === 'true' || $key === 'false' || $key === 'null' || $key === 'yes' || $key === 'no') {
            return "'" . $key . "'";
        }

        if (preg_match('/^[A-Za-z0-9_.\/-]+$/', $key) === 1) {
            return $key;
        }

        return "'" . str_replace("'", "''", $key) . "'";
    }

    private function formatScalar(mixed $value): string
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
            throw new InvalidArgumentException(
                'WorkflowYamlWriter only emits scalar leaves, got ' . get_debug_type($value) . '.',
            );
        }

        if ($value === '') {
            return "''";
        }

        if ($value === 'true' || $value === 'false' || $value === 'null' || $value === 'yes' || $value === 'no'
            || $value === 'on' || $value === 'off') {
            return "'" . $value . "'";
        }

        if (preg_match('/^[0-9]/', $value) === 1 && is_numeric($value)) {
            return "'" . $value . "'";
        }

        if (preg_match('/[:#\[\]{}|>&*!%@`\'",?]/', $value) === 1) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return $value;
    }

    /** @param array<array-key, mixed> $value */
    private function isList(array $value): bool
    {
        return $value !== [] && array_is_list($value);
    }

    /** @param list<mixed> $value */
    private function isListOfScalars(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $value */
    private function isListOfMaps(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_array($item) || $this->isList($item)) {
                return false;
            }
        }

        return true;
    }
}
