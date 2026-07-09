<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover\Edge;

/**
 * Port: read the edge's on-disk boot config (the file the proxy boots from). Used by the drift
 * detector to confirm the durable file still matches the recorded intent, so a cold restart self-heals.
 */
interface BootConfigReaderInterface
{
    /** The boot config contents, or null when the file is missing/empty. */
    public function read(): ?string;
}
