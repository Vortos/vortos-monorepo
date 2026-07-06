<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Verification;

/**
 * Classifies an action's `runs.using` runtime for the pin verifier.
 *
 * GitHub retires Node major runtimes over time: node12 and node16 have been **removed** (workflows
 * using them fail), node20 is **deprecated** (still runs — GitHub force-migrates it to node24 — but
 * emits a warning on every run), and node24 is current. Docker/composite actions carry no Node
 * runtime and are unaffected.
 *
 * The verifier fails closed only on **removed** runtimes: those genuinely break, and are always
 * actionable (a newer major exists). It surfaces **deprecated** runtimes as advisories, because an
 * action's latest major may still be node20 with no node24 release available — failing closed there
 * would be unsatisfiable, not bulletproof.
 *
 * The runtime→status mapping lives in {@see ActionRuntimeStatus}.
 */
final class ActionRuntime
{
    /** Node runtimes GitHub has removed — a hard, fail-closed error. */
    private const REMOVED = ['node12', 'node16'];

    /** Node runtimes GitHub has deprecated but still executes (auto-upgraded) — advisory only. */
    private const DEPRECATED = ['node20'];

    public static function classify(?string $using): ActionRuntimeStatus
    {
        if ($using === null || $using === '') {
            return ActionRuntimeStatus::Unknown;
        }

        $normalized = strtolower(trim($using, " \t\n\r\0\x0B\"'"));

        if (in_array($normalized, self::REMOVED, true)) {
            return ActionRuntimeStatus::Removed;
        }
        if (in_array($normalized, self::DEPRECATED, true)) {
            return ActionRuntimeStatus::Deprecated;
        }

        return ActionRuntimeStatus::Current;
    }
}
