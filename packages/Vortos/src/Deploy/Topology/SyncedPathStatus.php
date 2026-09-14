<?php

declare(strict_types=1);

namespace Vortos\Deploy\Topology;

enum SyncedPathStatus: string
{
    /** Every file already matched the image by content, mode and owner. */
    case InSync = 'in_sync';

    /** The path did not exist on the host. */
    case Installed = 'installed';

    /** At least one file was written, re-moded, re-owned or removed. */
    case Updated = 'updated';
}
