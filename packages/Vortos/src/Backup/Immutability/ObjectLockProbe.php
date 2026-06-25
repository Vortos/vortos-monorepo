<?php

declare(strict_types=1);

namespace Vortos\Backup\Immutability;

use Vortos\Backup\Domain\ObjectLockPolicy;
use Vortos\Backup\Port\BackupStoreInterface;

/**
 * Reads head() metadata to verify that the declared Object Lock policy is actually
 * configured on the store — keeping the `object_lock` capability honest.
 */
final class ObjectLockProbe
{
    public function verify(BackupStoreInterface $store, string $testKey, ObjectLockPolicy $declared): bool
    {
        if (!$store->exists($testKey)) {
            return false;
        }

        try {
            $verifier = new ImmutabilityVerifier();
            $verifier->assertDeleteRejected($store, $testKey);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
