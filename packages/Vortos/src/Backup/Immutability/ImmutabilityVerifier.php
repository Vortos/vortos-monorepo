<?php

declare(strict_types=1);

namespace Vortos\Backup\Immutability;

use RuntimeException;
use Vortos\Backup\Port\BackupStoreInterface;

/**
 * Asserts that a delete attempt on a locked object is rejected.
 * An unexpected successful delete means the lock is not configured — the §12.7
 * ransomware/compromised-key test.
 */
final class ImmutabilityVerifier
{
    /**
     * @throws RuntimeException if the delete unexpectedly succeeds
     */
    public function assertDeleteRejected(BackupStoreInterface $store, string $key): void
    {
        try {
            $store->delete($key);
        } catch (\Throwable) {
            // Expected: the delete was rejected by the lock.
            return;
        }

        // If we get here, the delete succeeded — the lock is not working.
        throw new RuntimeException(sprintf(
            'Immutability violation: delete of locked object "%s" was NOT rejected. Object Lock may not be configured.',
            $key,
        ));
    }
}
