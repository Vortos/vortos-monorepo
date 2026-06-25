<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use InvalidArgumentException;

/**
 * Alertmanager-style root-cause suppression (§3.3, improvement #8): while
 * `sourceRuleId` is actively firing, `suppressedRuleId` is inhibited for the window —
 * one root cause is one page, not fifty. Declared config, validated like rules.
 */
final readonly class InhibitionRule
{
    public function __construct(
        public string $sourceRuleId,
        public string $suppressedRuleId,
        public int $windowSeconds,
    ) {
        if ($sourceRuleId === '' || $suppressedRuleId === '') {
            throw new InvalidArgumentException('InhibitionRule sourceRuleId/suppressedRuleId must not be empty.');
        }
        if ($sourceRuleId === $suppressedRuleId) {
            throw new InvalidArgumentException('InhibitionRule sourceRuleId and suppressedRuleId must differ.');
        }
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('InhibitionRule windowSeconds must be >= 1.');
        }
    }
}
