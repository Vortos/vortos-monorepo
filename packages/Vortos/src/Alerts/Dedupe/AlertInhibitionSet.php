<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

/**
 * A declared collection of {@see InhibitionRule} — the app's root-cause suppression policy.
 *
 * Parallel to {@see \Vortos\Alerts\Rule\AlertRuleSet}: rules are declared in config and assembled
 * once, so the dispatcher is handed a validated set rather than reaching for configuration itself.
 * An empty set (the default) means no suppression — every alert routes on its own merits.
 */
final class AlertInhibitionSet
{
    /** @var list<InhibitionRule> */
    private array $rules;

    /** @param list<InhibitionRule> $rules */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /** @return list<InhibitionRule> */
    public function all(): array
    {
        return $this->rules;
    }
}
