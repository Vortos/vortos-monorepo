<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

use RuntimeException;

/**
 * Thrown by {@see AlertRuleValidator} at config-validation time — never at fire time.
 * Carries every violation found (not just the first) so a misconfiguration is fixed
 * in one pass, surfaced verbatim by {@see \Vortos\Alerts\Preflight\AlertRulesDoctorCheck}.
 */
final class AlertRuleValidationException extends RuntimeException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(sprintf(
            '%d alert rule validation violation(s): %s',
            count($violations),
            implode('; ', $violations),
        ));
    }
}
