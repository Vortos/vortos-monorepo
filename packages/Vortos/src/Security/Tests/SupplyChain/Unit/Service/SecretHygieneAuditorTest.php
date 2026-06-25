<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Security\SupplyChain\Service\SecretAuditEntry;
use Vortos\Security\SupplyChain\Service\SecretHygieneAuditor;

final class SecretHygieneAuditorTest extends TestCase
{
    private SecretHygieneAuditor $auditor;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->auditor = new SecretHygieneAuditor();
        $this->now = new \DateTimeImmutable('2024-06-01T00:00:00Z');
    }

    public function test_stale_secret_detected(): void
    {
        $entry = new SecretAuditEntry(
            id: 'db-password',
            rotationIntervalSeconds: 86400,
            lastRotatedAt: new \DateTimeImmutable('2024-05-01T00:00:00Z'),
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertCount(1, $findings);
        self::assertSame('stale', $findings[0]->kind);
        self::assertSame('db-password', $findings[0]->secretId);
    }

    public function test_fresh_secret_clean(): void
    {
        $entry = new SecretAuditEntry(
            id: 'db-password',
            rotationIntervalSeconds: 86400,
            lastRotatedAt: new \DateTimeImmutable('2024-05-31T23:00:00Z'),
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertSame([], $findings);
    }

    public function test_leaked_aws_key_detected(): void
    {
        $entry = new SecretAuditEntry(
            id: 'env-file',
            rawValue: 'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE',
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertCount(1, $findings);
        self::assertSame('leaked', $findings[0]->kind);
    }

    public function test_leaked_private_key_detected(): void
    {
        $entry = new SecretAuditEntry(
            id: 'ssh-key',
            rawValue: '-----BEGIN RSA PRIVATE KEY----- ...',
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertCount(1, $findings);
        self::assertSame('leaked', $findings[0]->kind);
    }

    public function test_leaked_github_token_detected(): void
    {
        $entry = new SecretAuditEntry(
            id: 'token',
            rawValue: 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh1234',
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertCount(1, $findings);
        self::assertSame('leaked', $findings[0]->kind);
    }

    public function test_allowlisted_pattern_skipped(): void
    {
        $entry = new SecretAuditEntry(
            id: 'env-file',
            rawValue: 'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE',
        );

        $findings = $this->auditor->audit([$entry], $this->now, ['aws_access_key']);
        self::assertSame([], $findings);
    }

    public function test_clean_value_no_findings(): void
    {
        $entry = new SecretAuditEntry(
            id: 'config',
            rawValue: 'APP_NAME=vortos',
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertSame([], $findings);
    }

    public function test_no_rotation_policy_no_stale_finding(): void
    {
        $entry = new SecretAuditEntry(id: 'static-key');

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertSame([], $findings);
    }

    public function test_both_stale_and_leaked(): void
    {
        $entry = new SecretAuditEntry(
            id: 'old-token',
            rotationIntervalSeconds: 3600,
            lastRotatedAt: new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            rawValue: 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefgh1234',
        );

        $findings = $this->auditor->audit([$entry], $this->now);
        self::assertCount(2, $findings);
        $kinds = array_map(fn ($f) => $f->kind, $findings);
        self::assertContains('stale', $kinds);
        self::assertContains('leaked', $kinds);
    }
}
