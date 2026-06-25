<?php

declare(strict_types=1);

namespace Vortos\Migration\Safety\Rule;

use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\SafetyDiagnostic;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TargetSchemaSnapshot;

final class SafetyRuleSet
{
    /** @var array<string, SafetyRuleInterface> */
    private array $rules = [];

    /** @var array<string, Severity> */
    private array $overrides;

    /** @param array<string, Severity> $severityOverrides */
    public function __construct(array $severityOverrides = [])
    {
        $this->overrides = $severityOverrides;
    }

    public function add(SafetyRuleInterface $rule): void
    {
        $this->rules[$rule->id()] = $rule;
    }

    /** @return list<SafetyDiagnostic> */
    public function evaluate(
        MigrationArtifact $artifact,
        ?TargetSchemaSnapshot $target,
        ParsedStatement $statement,
    ): array {
        $diagnostics = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->evaluate($artifact, $target, $statement) as $diag) {
                $diagnostics[] = $this->applyOverride($diag);
            }
        }

        return $diagnostics;
    }

    /** @return list<string> */
    public function ruleIds(): array
    {
        $ids = array_keys($this->rules);
        sort($ids);

        return $ids;
    }

    public function validateOverrides(): void
    {
        foreach (array_keys($this->overrides) as $id) {
            if (!isset($this->rules[$id])) {
                throw new \InvalidArgumentException(sprintf(
                    'Unknown safety rule id "%s" in severity overrides. Known rules: [%s].',
                    $id,
                    implode(', ', $this->ruleIds()),
                ));
            }
        }
    }

    private function applyOverride(SafetyDiagnostic $diag): SafetyDiagnostic
    {
        if (!isset($this->overrides[$diag->ruleId])) {
            return $diag;
        }

        $override = $this->overrides[$diag->ruleId];

        if ($override === $diag->severity) {
            return $diag;
        }

        return new SafetyDiagnostic(
            $diag->ruleId,
            $override,
            $diag->table,
            $diag->statementExcerpt,
            $diag->message,
            $diag->remediation,
            $diag->optOutAttribute,
        );
    }
}
