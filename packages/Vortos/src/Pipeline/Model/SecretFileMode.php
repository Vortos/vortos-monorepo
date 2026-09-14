<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * The only permissions a materialised per-service secret file may carry on the host (RC-4).
 *
 * A closed set rather than an int so no declaration can hand a secret group or world read by typo:
 * the deploy one-shot writes these files as root, and the compose CLI (also root) is their only reader.
 */
enum SecretFileMode: int
{
    case OwnerRead = 0o400;
    case OwnerReadWrite = 0o600;

    /** Four-digit octal, the form chmod and the reveal script take. */
    public function octal(): string
    {
        return sprintf('%04o', $this->value);
    }
}
