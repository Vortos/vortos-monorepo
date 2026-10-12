<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;

/**
 * Where the recovery-time projection finds its anchor: the framework's own base backups and WAL
 * catalog, or pgBackRest's repository. Throws when the source cannot be read.
 */
interface PitrAnchorReaderInterface
{
    public function read(DateTimeImmutable $now): PitrAnchorReading;
}
