<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Immutability;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\Domain\RetentionPlan;
use Vortos\Backup\Immutability\ImmutabilityVerifier;
use Vortos\Backup\Port\BackupStoreInterface;
use Vortos\Backup\Port\BackupStream;
use Vortos\Backup\Port\StoredBackup;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class ImmutabilityVerifierTest extends TestCase
{
    public function test_assert_delete_rejected_passes_when_store_throws(): void
    {
        $store = new class implements BackupStoreInterface {
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
            public function store(BackupStream $s, string $k): StoredBackup { throw new RuntimeException(); }
            public function open(string $k): mixed { throw new RuntimeException(); }
            public function exists(string $k): bool { return true; }
            public function delete(string $k): void { throw new RuntimeException('Object is locked.'); }
            public function list(string $p): array { return []; }
            public function applyRetention(RetentionPlan $p): void {}
        };

        $verifier = new ImmutabilityVerifier();
        $verifier->assertDeleteRejected($store, 'test-key');
        $this->assertTrue(true); // no exception = pass
    }

    public function test_assert_delete_rejected_raises_when_delete_succeeds(): void
    {
        $store = new class implements BackupStoreInterface {
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }
            public function store(BackupStream $s, string $k): StoredBackup { throw new RuntimeException(); }
            public function open(string $k): mixed { throw new RuntimeException(); }
            public function exists(string $k): bool { return true; }
            public function delete(string $k): void { /* succeeds — lock not configured */ }
            public function list(string $p): array { return []; }
            public function applyRetention(RetentionPlan $p): void {}
        };

        $verifier = new ImmutabilityVerifier();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Immutability violation/');

        $verifier->assertDeleteRejected($store, 'test-key');
    }
}
