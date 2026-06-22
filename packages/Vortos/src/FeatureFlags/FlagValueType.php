<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

/**
 * The value semantics of a flag — distinct from the flag *kind*
 * (release/experiment/ops/permission, see {@see FlagKind}).
 *
 * - Bool   → classic on/off flag (delivered via the `flags` wire channel).
 * - String → a string treatment (delivered via the `variants` wire channel).
 * - Number → a numeric treatment (server-side only in Phase A).
 * - Json   → a remote-config blob (delivered via the `payloads` wire channel).
 */
enum FlagValueType: string
{
    case Bool   = 'bool';
    case String = 'string';
    case Number = 'number';
    case Json   = 'json';

    /** The guaranteed safe fallback for this type when nothing else can be resolved. */
    public function zeroValue(): bool|string|float|array|null
    {
        return match ($this) {
            self::Bool   => false,
            self::String => '',
            self::Number => 0.0,
            self::Json   => null,
        };
    }
}
