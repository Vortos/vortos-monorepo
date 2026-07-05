<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Definition;

/**
 * How a generated quality stage (static analysis, agnosticism lint) behaves (G6). The framework
 * cannot guarantee the app has the tool installed or the tests present, so the stage is declarative:
 *
 *   - Enforce — run it and fail the build on issues (or on a missing tool). The strict default.
 *   - Warn    — run it if the tool is present, surface issues as GitHub warnings, never fail the
 *               build; skip cleanly (no failure) if the tool is not installed. The right setting
 *               while an app is adopting the tool.
 *   - Off     — do not emit the stage at all.
 */
enum QualityMode: string
{
    case Enforce = 'enforce';
    case Warn = 'warn';
    case Off = 'off';

    public function emits(): bool
    {
        return $this !== self::Off;
    }
}
