<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

/** `health_probe_failing` / `backup_failed` — the sample's own boolean carries the signal. */
final readonly class NoCondition implements AlertConditionInterface
{
}
