<?php

declare(strict_types=1);

namespace Vortos\Secrets\Value;

/**
 * The lifecycle state of one secret version.
 *
 * `Active` → the current version. `WithinGrace` → a previous version still valid
 * during a rotation overlap window. `Retired` → grace window elapsed, no longer
 * valid. `Revoked` → explicitly invalidated before its grace window would have
 * elapsed (e.g. a confirmed leak).
 */
enum SecretVersionState: string
{
    case Active = 'active';
    case WithinGrace = 'within_grace';
    case Retired = 'retired';
    case Revoked = 'revoked';

    public function isValid(): bool
    {
        return $this === self::Active || $this === self::WithinGrace;
    }
}
