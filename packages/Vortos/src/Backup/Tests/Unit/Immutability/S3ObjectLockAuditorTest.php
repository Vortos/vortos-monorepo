<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Immutability;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Immutability\S3ObjectLockAuditor;
use Vortos\Backup\PgBackRest\PgBackRestOffsite;

final class S3ObjectLockAuditorTest extends TestCase
{
    private const VERSIONED = ['Status' => 'Enabled'];

    public function testAVersionedBucketUnderACompliancelockAsLongAsDeclaredIsImmutable(): void
    {
        $audit = S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, self::lock('COMPLIANCE', days: 21));

        self::assertSame([], $audit->problems);
        self::assertSame(['offsite_versioning' => 'Enabled', 'offsite_object_lock' => 'Enabled', 'offsite_lock_mode' => 'COMPLIANCE', 'offsite_lock_days' => 21], $audit->facts);
    }

    public function testYearsCountAsDays(): void
    {
        self::assertSame([], S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, self::lock('COMPLIANCE', years: 1))->problems);
    }

    public function testGovernanceIsRefusedBecauseTheBypassPermissionRemovesIt(): void
    {
        $problems = S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, self::lock('GOVERNANCE', days: 30))->problems;

        self::assertSame(['the off-site lock is GOVERNANCE, which a key with the bypass permission can remove; it must be COMPLIANCE'], $problems);
    }

    public function testALockShorterThanTheRestoreWindowLeavesTheStartOfTheChainDestroyable(): void
    {
        $problems = S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, self::lock('COMPLIANCE', days: 14))->problems;

        self::assertSame(['the off-site lock keeps versions 14 days; the restore window needs at least 21'], $problems);
    }

    public function testNoLockOrNoDefaultRetentionOrNoVersioningIsNotImmutable(): void
    {
        self::assertSame(['Object Lock is not enabled on the off-site bucket'], S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, [])->problems);
        self::assertSame(
            ['the off-site bucket has no default retention: new backups arrive unlocked'],
            S3ObjectLockAuditor::judge(self::offsite(), self::VERSIONED, ['ObjectLockConfiguration' => ['ObjectLockEnabled' => 'Enabled']])->problems,
        );
        self::assertSame(
            ['versioning on the off-site bucket is Suspended: a delete there destroys, it does not hide'],
            S3ObjectLockAuditor::judge(self::offsite(), ['Status' => 'Suspended'], self::lock('COMPLIANCE', days: 21))->problems,
        );
    }

    public function testWithoutTheAuditKeyTheLockIsReportedUnauditedNeverAssumed(): void
    {
        $audit = (new S3ObjectLockAuditor(env: static fn (string $name): string|false => false))->audit(self::offsite());

        self::assertSame(['the off-site lock cannot be audited: VORTOS_BACKUP_OFFSITE_AUDIT_ACCESS_KEY_ID / VORTOS_BACKUP_OFFSITE_AUDIT_SECRET_ACCESS_KEY are not set on this node'], $audit->problems);
    }

    public function testReadsBothSettingsFromTheProviderWithTheAuditKey(): void
    {
        $mock = new MockHandler([new Result(self::VERSIONED), new Result(self::lock('COMPLIANCE', days: 21))]);
        $seen = [];
        $auditor = new S3ObjectLockAuditor(
            clientFactory: static function (PgBackRestOffsite $o, string $key, string $secret) use ($mock, &$seen): S3Client {
                $seen = [$o->endpoint, $key, $secret];

                return new S3Client(['version' => '2006-03-01', 'region' => $o->region, 'endpoint' => $o->endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => $key, 'secret' => $secret], 'handler' => $mock]);
            },
            env: static fn (string $name): string => $name === 'VORTOS_BACKUP_OFFSITE_AUDIT_ACCESS_KEY_ID' ? 'audit-key' : 'audit-secret',
        );

        self::assertSame([], $auditor->audit(self::offsite())->problems);
        self::assertSame(['https://s3.us-west-004.backblazeb2.com', 'audit-key', 'audit-secret'], $seen);
    }

    public function testAProviderRefusalIsAProblemNotAPass(): void
    {
        $mock = new MockHandler([static fn (Command $cmd): S3Exception => new S3Exception("Access Denied\nrequest id 1", $cmd)]);
        $auditor = new S3ObjectLockAuditor(
            clientFactory: static fn (PgBackRestOffsite $o, string $k, string $s): S3Client => new S3Client(['version' => '2006-03-01', 'region' => $o->region, 'endpoint' => $o->endpoint, 'credentials' => ['key' => $k, 'secret' => $s], 'handler' => $mock]),
            env: static fn (string $name): string => 'x',
        );

        self::assertSame(['cannot read the off-site bucket\'s lock: Access Denied'], $auditor->audit(self::offsite())->problems);
    }

    /** @return array<string, mixed> */
    private static function lock(string $mode, ?int $days = null, ?int $years = null): array
    {
        $retention = ['Mode' => $mode] + ($days !== null ? ['Days' => $days] : []) + ($years !== null ? ['Years' => $years] : []);

        return ['ObjectLockConfiguration' => ['ObjectLockEnabled' => 'Enabled', 'Rule' => ['DefaultRetention' => $retention]]];
    }

    private static function offsite(): PgBackRestOffsite
    {
        return new PgBackRestOffsite(2, 'acme-pgbackrest-offsite', 'https://s3.us-west-004.backblazeb2.com', 'us-west-004', 21);
    }
}
