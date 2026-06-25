<?php

declare(strict_types=1);

namespace Vortos\Backup\Port\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * The capabilities a backup *store* (destination) may declare.
 *
 * `versioning`, `object_lock` and `cross_region` are the levers Block 20 lights up
 * (immutability + 3-2-1). They are declared honestly here so `deploy:doctor` and the
 * TCK reflect what the store can actually enforce today.
 */
enum BackupStoreCapability: string implements CapabilityKey
{
    /** Accepts a streamed, multipart upload (bounded memory for huge artifacts). */
    case StreamingMultipart = 'streaming_multipart';

    /** Can enumerate and delete stored artifacts for retention. */
    case Retention = 'retention';

    /** Object versioning (Block 20). */
    case Versioning = 'versioning';

    /** Object-lock / WORM immutability (Block 20). */
    case ObjectLock = 'object_lock';

    /** A cross-provider / cross-region copy for 3-2-1 (Block 20). */
    case CrossRegion = 'cross_region';

    public function key(): string
    {
        return $this->value;
    }
}
