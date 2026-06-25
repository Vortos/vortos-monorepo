<?php

declare(strict_types=1);

namespace Vortos\Alerts\Dedupe;

use Vortos\Alerts\Event\AlertEvent;

/**
 * `sha256(canonical(ruleId|env|tenantId|sortedLabels))` — stable across identical
 * re-fires, order-independent on labels (matches the Block 16 hash-chain canonical
 * discipline), and changes iff identity actually changes.
 */
final class Fingerprint
{
    public static function of(AlertEvent $event): string
    {
        return self::compute($event->ruleId, $event->env, $event->tenantId, $event->labels);
    }

    /** @param array<string, string> $labels */
    public static function compute(string $ruleId, string $env, ?string $tenantId, array $labels): string
    {
        ksort($labels);
        $canonical = implode('|', [
            $ruleId,
            $env,
            $tenantId ?? '',
            implode(',', array_map(static fn (string $k, string $v): string => "{$k}={$v}", array_keys($labels), $labels)),
        ]);

        return hash('sha256', $canonical);
    }
}
