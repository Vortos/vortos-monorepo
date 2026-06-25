<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use DateTimeImmutable;

/**
 * Pure: decides whether a candidate rule's alert is suppressed because a declared
 * {@see InhibitionRule}'s source is actively (recently) firing. "Active" is
 * caller-supplied so this stays decoupled from any particular state store.
 */
final class Inhibitor
{
    /**
     * @param list<InhibitionRule> $rules
     * @param callable(string $ruleId): bool $isSourceActive
     */
    public function shouldSuppress(array $rules, string $candidateRuleId, callable $isSourceActive, DateTimeImmutable $now): bool
    {
        foreach ($rules as $rule) {
            if ($rule->suppressedRuleId !== $candidateRuleId) {
                continue;
            }
            if ($isSourceActive($rule->sourceRuleId)) {
                return true;
            }
        }

        return false;
    }
}
