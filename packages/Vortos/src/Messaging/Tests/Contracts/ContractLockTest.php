<?php

declare(strict_types=1);

namespace Vortos\Messaging\Tests\Contracts;

use PHPUnit\Framework\TestCase;
use Vortos\Messaging\Contracts\ContractLock;

final readonly class LockTestEventV1
{
    public function __construct(
        public string $entryId,
        public string $name,
    ) {}
}

final readonly class LockTestEventRenamedField
{
    public function __construct(
        public string $entryId,
        public string $fullName,
    ) {}
}

final class ContractLockTest extends TestCase
{
    private function wireMap(string $class = LockTestEventV1::class, int $version = 1): array
    {
        return [$class => ['name' => 'messaging.lock_test_event', 'version' => $version]];
    }

    public function test_compute_snapshots_name_version_and_schema(): void
    {
        $contracts = ContractLock::compute($this->wireMap());

        $this->assertSame(
            [
                'messaging.lock_test_event' => [
                    'class'   => LockTestEventV1::class,
                    'version' => 1,
                    'schema'  => ['entryId' => 'string', 'name' => 'string'],
                ],
            ],
            $contracts,
        );
    }

    public function test_no_drift_when_unchanged(): void
    {
        $locked = ContractLock::compute($this->wireMap());

        $this->assertSame([], ContractLock::diff($locked, ContractLock::compute($this->wireMap())));
    }

    public function test_schema_change_without_version_bump_is_drift(): void
    {
        $locked  = ContractLock::compute($this->wireMap());
        // Same wire name now produced by a class whose payload shape differs
        $current = ContractLock::compute($this->wireMap(LockTestEventRenamedField::class));

        $findings = ContractLock::diff($locked, $current);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('WITHOUT a version bump', $findings[0]);
        $this->assertStringContainsString('version: 2', $findings[0]);
    }

    public function test_version_bump_with_schema_change_is_relock_reminder_only(): void
    {
        $locked  = ContractLock::compute($this->wireMap());
        $current = ContractLock::compute($this->wireMap(LockTestEventRenamedField::class, version: 2));

        $findings = ContractLock::diff($locked, $current);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('version changed v1 → v2', $findings[0]);
        $this->assertStringNotContainsString('WITHOUT', $findings[0]);
    }

    public function test_disappeared_contract_is_drift_with_rename_hint(): void
    {
        $locked  = ContractLock::compute($this->wireMap());
        // Class renamed without as: — convention derives a NEW wire name
        $current = ContractLock::compute([
            LockTestEventRenamedField::class => ['name' => 'messaging.lock_test_event_renamed_field', 'version' => 1],
        ]);

        $findings = ContractLock::diff($locked, $current);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("as: 'messaging.lock_test_event'", $findings[0]);
        $this->assertStringContainsString('not in the lockfile', $findings[1]);
    }

    public function test_write_and_load_roundtrip(): void
    {
        $path      = sys_get_temp_dir() . '/vortos-contract-lock-test-' . uniqid() . '.lock';
        $contracts = ContractLock::compute($this->wireMap());

        try {
            ContractLock::write($path, $contracts);
            $this->assertSame($contracts, ContractLock::load($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_load_missing_file_returns_null(): void
    {
        $this->assertNull(ContractLock::load('/nonexistent/contracts.lock'));
    }
}
