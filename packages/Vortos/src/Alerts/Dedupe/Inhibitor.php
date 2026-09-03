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
     * @param callable(string $ruleId, int $windowSeconds): bool $isSourceActive whether the source
     *        rule has fired within the last $windowSeconds — the window is the rule's own, so the
     *        caller checks activity against exactly the horizon each inhibition declares.
     */
    public function shouldSuppress(array $rules, string $candidateRuleId, callable $isSourceActive, DateTimeImmutable $now): bool
    {
        foreach ($rules as $rule) {
            if ($rule->suppressedRuleId !== $candidateRuleId) {
                continue;
            }
            if ($isSourceActive($rule->sourceRuleId, $rule->windowSeconds)) {
                return true;
            }
        }

        return false;
    }
}
