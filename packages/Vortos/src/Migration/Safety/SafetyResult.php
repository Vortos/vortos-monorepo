<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety;

final readonly class SafetyResult
{
    /** @param list<SafetyDiagnostic> $diagnostics */
    public function __construct(
        public string $engine,
        public array $diagnostics,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->diagnostics as $d) {
            if ($d->severity === Severity::Error) {
                return true;
            }
        }

        return false;
    }

    /** @return list<SafetyDiagnostic> */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->diagnostics,
            static fn (SafetyDiagnostic $d) => $d->severity === Severity::Error,
        ));
    }

    /** @return array<string, list<SafetyDiagnostic>> */
    public function bySeverity(): array
    {
        $grouped = [];

        foreach (Severity::cases() as $sev) {
            $grouped[$sev->value] = [];
        }

        foreach ($this->diagnostics as $d) {
            $grouped[$d->severity->value][] = $d;
        }

        return $grouped;
    }

    /** @return array{engine: string, diagnostics: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $sorted = $this->diagnostics;
        usort($sorted, static function (SafetyDiagnostic $a, SafetyDiagnostic $b): int {
            return $a->severity->sortOrder() <=> $b->severity->sortOrder()
                ?: $a->ruleId <=> $b->ruleId
                ?: ($a->table ?? '') <=> ($b->table ?? '');
        });

        return [
            'diagnostics' => array_map(
                static fn (SafetyDiagnostic $d) => $d->toArray(),
                $sorted,
            ),
            'engine' => $this->engine,
        ];
    }

    public static function clean(string $engine): self
    {
        return new self($engine, []);
    }

    public static function merge(string $engine, self ...$results): self
    {
        $all = [];
        foreach ($results as $r) {
            foreach ($r->diagnostics as $d) {
                $all[] = $d;
            }
        }

        return new self($engine, $all);
    }
}
