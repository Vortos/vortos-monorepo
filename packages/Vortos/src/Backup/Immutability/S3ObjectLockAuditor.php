<?php

declare(strict_types=1);

namespace Vortos\Backup\Immutability;

use Aws\S3\S3Client;
use Closure;
use Throwable;
use Vortos\Backup\PgBackRest\PgBackRestOffsite;

/**
 * Audits an S3-compatible bucket's Object Lock (Backblaze B2, AWS S3, Wasabi …) with a read-only key.
 *
 * Immutable means all three, read back from the provider:
 *  - versioning is on — a delete only hides, and the hidden version is what --repo-target-time reads;
 *  - the default retention is COMPLIANCE — GOVERNANCE can be bypassed by any key holding the bypass
 *    permission, which is exactly what an attacker with the right key would use;
 *  - it lasts at least the declared minimum.
 *
 * The key is read from the environment when the audit runs (the probe runs in a long-lived worker),
 * and it needs only to read bucket settings: on B2 `readBuckets` + `readBucketRetentions`, on AWS
 * `s3:GetBucketObjectLockConfiguration` + `s3:GetBucketVersioning`. It can read no object and write
 * nothing, so holding it on the backup node costs nothing.
 */
final class S3ObjectLockAuditor implements OffsiteImmutabilityAuditorInterface
{
    /**
     * @param (Closure(PgBackRestOffsite, string, string): S3Client)|null $clientFactory for tests
     * @param (Closure(string): (string|false))|null                    $env           for tests
     */
    public function __construct(
        private readonly ?Closure $clientFactory = null,
        private readonly ?Closure $env = null,
    ) {}

    public function audit(PgBackRestOffsite $offsite): ImmutabilityAudit
    {
        $keyName = $offsite->auditCredentialEnvPrefix . '_ACCESS_KEY_ID';
        $secretName = $offsite->auditCredentialEnvPrefix . '_SECRET_ACCESS_KEY';
        $key = $this->env($keyName);
        $secret = $this->env($secretName);
        $facts = ['offsite_bucket' => $offsite->bucket, 'offsite_minimum_lock_days' => $offsite->minimumLockDays];

        if ($key === '' || $secret === '') {
            return new ImmutabilityAudit([sprintf('the off-site lock cannot be audited: %s / %s are not set on this node', $keyName, $secretName)], $facts);
        }

        try {
            $client = $this->clientFactory !== null
                ? ($this->clientFactory)($offsite, $key, $secret)
                : new S3Client([
                    'version' => '2006-03-01',
                    'region' => $offsite->region,
                    'endpoint' => $offsite->endpoint,
                    'use_path_style_endpoint' => true,
                    'credentials' => ['key' => $key, 'secret' => $secret],
                    'http' => ['timeout' => 15, 'connect_timeout' => 5],
                    'retries' => 2,
                ]);
            $versioning = $client->getBucketVersioning(['Bucket' => $offsite->bucket])->toArray();
            $lock = $client->getObjectLockConfiguration(['Bucket' => $offsite->bucket])->toArray();
        } catch (Throwable $e) {
            return new ImmutabilityAudit([sprintf('cannot read the off-site bucket\'s lock: %s', self::firstLine($e->getMessage()))], $facts);
        }

        return self::judge($offsite, $versioning, $lock, $facts);
    }

    /**
     * @param array<mixed>         $versioning GetBucketVersioning
     * @param array<mixed>         $lock       GetObjectLockConfiguration
     * @param array<string, mixed> $facts
     */
    public static function judge(PgBackRestOffsite $offsite, array $versioning, array $lock, array $facts = []): ImmutabilityAudit
    {
        $problems = [];

        $status = \is_string($versioning['Status'] ?? null) ? $versioning['Status'] : 'Disabled';
        $facts['offsite_versioning'] = $status;
        if ($status !== 'Enabled') {
            $problems[] = sprintf('versioning on the off-site bucket is %s: a delete there destroys, it does not hide', $status);
        }

        $configuration = \is_array($lock['ObjectLockConfiguration'] ?? null) ? $lock['ObjectLockConfiguration'] : [];
        $enabled = ($configuration['ObjectLockEnabled'] ?? null) === 'Enabled';
        $retention = \is_array($configuration['Rule']['DefaultRetention'] ?? null) ? $configuration['Rule']['DefaultRetention'] : [];
        $mode = \is_string($retention['Mode'] ?? null) ? $retention['Mode'] : null;
        $days = match (true) {
            \is_numeric($retention['Days'] ?? null) => (int) $retention['Days'],
            \is_numeric($retention['Years'] ?? null) => (int) $retention['Years'] * 365,
            default => 0,
        };
        $facts += ['offsite_object_lock' => $enabled ? 'Enabled' : 'Disabled', 'offsite_lock_mode' => $mode, 'offsite_lock_days' => $days];

        if (!$enabled) {
            $problems[] = 'Object Lock is not enabled on the off-site bucket';
        } elseif ($mode === null || $days === 0) {
            $problems[] = 'the off-site bucket has no default retention: new backups arrive unlocked';
        } else {
            if ($mode !== 'COMPLIANCE') {
                $problems[] = sprintf('the off-site lock is %s, which a key with the bypass permission can remove; it must be COMPLIANCE', $mode);
            }
            if ($days < $offsite->minimumLockDays) {
                $problems[] = sprintf('the off-site lock keeps versions %d days; the restore window needs at least %d', $days, $offsite->minimumLockDays);
            }
        }

        return new ImmutabilityAudit($problems, $facts);
    }

    private function env(string $name): string
    {
        $value = $this->env !== null ? ($this->env)($name) : getenv($name);

        return \is_string($value) ? trim($value) : '';
    }

    private static function firstLine(string $message): string
    {
        $line = strtok($message, "\n");

        return $line === false ? $message : $line;
    }
}
