<?php

declare(strict_types=1);

namespace Vortos\Pipeline\Model;

/**
 * The permissions a synced host file may carry (RC-3). A closed set so no declaration can make a file
 * group- or world-writable: the deploy writes these as root, and a writable copy is one a container
 * or another host user could quietly change back into drift.
 *
 * A file that is executable in the image keeps an execute bit for exactly the classes that may read it
 * (0644 → 0755, 0640 → 0750, 0600 → 0700); nothing else about the image's own mode is trusted.
 */
enum SyncedFileMode: int
{
    case WorldReadable = 0o644;
    case GroupReadable = 0o640;
    case OwnerReadable = 0o600;

    /** Four-digit octal, the form the deploy one-shot receives. */
    public function octal(): string
    {
        return sprintf('%04o', $this->value);
    }
}
