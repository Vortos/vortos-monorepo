<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

use InvalidArgumentException;
use Vortos\Alerts\Rule\Condition\AlertConditionInterface;
use Vortos\Alerts\Severity;

/**
 * A declared alert rule (§3.2) — validated at config time by {@see AlertRuleValidator},
 * never silently never-firing because of a typo discovered at 3am.
 */
final readonly class AlertRule
{
    /**
     * @param array<string, string> $labels
     * @param list<string>|null     $routingOverride explicit channel keys, bypassing the default matrix
     */
    public function __construct(
        public string $id,
        public Severity $severity,
        public AlertRuleKind $kind,
        public AlertConditionInterface $condition,
        public int $forDuration = 0,
        public ?string $sloRef = null,
        public ?string $metricRef = null,
        public ?array $routingOverride = null,
        public array $labels = [],
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('AlertRule id must not be empty.');
        }
        if ($forDuration < 0) {
            throw new InvalidArgumentException('AlertRule forDuration must be >= 0.');
        }
        if ($routingOverride !== null && $routingOverride === []) {
            throw new InvalidArgumentException('AlertRule routingOverride, if set, must not be an empty list.');
        }
    }
}
