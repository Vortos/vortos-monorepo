<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use InvalidArgumentException;

/**
 * pgBackRest's off-site repository: a second copy at another provider that a compromise of the server
 * cannot destroy (config/backup.php ->pgBackRest(offsite: ...)).
 *
 * WHY THIS SHAPE. Encryption protects a leaked bucket, not a compromised server: the server must hold a
 * key that writes, and pgBackRest's expiry needs that key to delete. A key that can delete can wipe a
 * repository. What it cannot do is remove an object version still under a COMPLIANCE-mode Object Lock
 * on a versioned bucket — not with that key, not with the account's master key. Its deletes only hide;
 * the versions stay, and pgBackRest's own `--repo-target-time` reads the repository as it was before.
 * That is the documented recovery for "deleted or corrupted by user accident or malware" (pgBackRest
 * help, repo-target-time), and the industry's immutable-copy control (3-2-1-1: one copy immutable).
 *
 * Only what the framework must check is declared here: the repository's index, and where its lock can
 * be read back. Bucket, endpoint, retention and cipher are pgBackRest's own configuration, as for the
 * primary; the application's contract test holds the two in step.
 *
 * {@see $minimumLockDays} is the shortest default retention the bucket may carry. It must outlast the
 * oldest backup a restore inside the retention window needs — retention plus one full-backup cycle — or
 * an attacker can still destroy the start of the chain.
 */
final readonly class PgBackRestOffsite
{
    public function __construct(
        /** pgBackRest's index for this repository (repo2-*). */
        public int $repo,
        public string $bucket,
        /** S3 endpoint URL, e.g. https://s3.us-west-004.backblazeb2.com */
        public string $endpoint,
        public string $region,
        public int $minimumLockDays,
        /**
         * Env prefix of the read-only key the health probe audits the lock with: <prefix>_ACCESS_KEY_ID and
         * <prefix>_SECRET_ACCESS_KEY. It reads bucket settings only, never objects; the write key stays
         * in the database container.
         */
        public string $auditCredentialEnvPrefix = 'VORTOS_BACKUP_OFFSITE_AUDIT',
    ) {
        if ($repo < 2 || $repo > 256) {
            throw new InvalidArgumentException(sprintf('The off-site pgBackRest repository index must be 2-256 (repo1 is the primary), got %d.', $repo));
        }
        if (preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) !== 1) {
            throw new InvalidArgumentException(sprintf('Off-site bucket must be a bucket name, got "%s".', $bucket));
        }
        if (preg_match('#^https://[a-z0-9.-]+(:\d+)?$#', $endpoint) !== 1) {
            throw new InvalidArgumentException(sprintf('Off-site endpoint must be an https:// host URL, got "%s".', $endpoint));
        }
        if (preg_match('/^[a-z0-9-]+$/', $region) !== 1) {
            throw new InvalidArgumentException(sprintf('Off-site region must be a region name, got "%s".', $region));
        }
        if ($minimumLockDays < 1) {
            throw new InvalidArgumentException('The off-site repository needs a lock of at least one day; without one it protects nothing.');
        }
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $auditCredentialEnvPrefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Audit credential env prefix must be an env name prefix, got "%s".', $auditCredentialEnvPrefix));
        }
    }
}
