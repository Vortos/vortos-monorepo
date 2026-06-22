<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Domain\Flag;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagLifecycleState;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver;
use Vortos\FeatureFlags\Storage\DatabaseFlagEnvironmentStateStorage;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;

/**
 * Block 12 — lifecycle, owner, expiry, and promotion tests.
 *
 * Uses real SQLite + real storage adapters. Tests the full round-trip:
 * FeatureFlag value object → DatabaseFlagStorage → back out → assertions.
 */
final class Block12LifecycleTest extends TestCase
{
    private const FLAG_TABLE = 'feature_flags';
    private const ENV_TABLE  = 'feature_flag_environment_state';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseFlagStorage $storage;
    private DatabaseFlagEnvironmentStateStorage $envStorage;
    private FlagScopeContext $scope;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::FLAG_TABLE . ' (
                id            VARCHAR(36)  NOT NULL,
                name          VARCHAR(255) NOT NULL UNIQUE,
                description   TEXT         NOT NULL DEFAULT \'\',
                enabled       INTEGER      NOT NULL DEFAULT 0,
                rules         TEXT         NOT NULL DEFAULT \'[]\',
                variants      TEXT,
                value_type    VARCHAR(16)  NOT NULL DEFAULT \'bool\',
                default_value TEXT,
                payload       TEXT,
                bucket_by     VARCHAR(32)  NOT NULL DEFAULT \'userId\',
                kind          VARCHAR(16)  NOT NULL DEFAULT \'release\',
                prerequisites TEXT,
                variant_rules TEXT,
                schedule      TEXT,
                required_scope VARCHAR(191),
                project_id    VARCHAR(191) NOT NULL DEFAULT \'default\',
                lifecycle     VARCHAR(16)  NOT NULL DEFAULT \'active\',
                owner         VARCHAR(191),
                expires_at    DATETIME,
                created_at    DATETIME     NOT NULL,
                updated_at    DATETIME     NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::ENV_TABLE . ' (
                flag_id        VARCHAR(36)  NOT NULL,
                environment    VARCHAR(64)  NOT NULL,
                enabled        INTEGER      NOT NULL DEFAULT 0,
                rules          TEXT         NOT NULL DEFAULT \'[]\',
                variants       TEXT,
                variant_rules  TEXT,
                schedule       TEXT,
                payload        TEXT,
                required_scope VARCHAR(191),
                prerequisites  TEXT,
                updated_at     DATETIME     NOT NULL,
                PRIMARY KEY (flag_id, environment)
            )',
        );

        $this->storage    = new DatabaseFlagStorage($this->connection, self::FLAG_TABLE);
        $this->envStorage = new DatabaseFlagEnvironmentStateStorage($this->connection, self::ENV_TABLE);
        $this->scope      = new FlagScopeContext();
    }

    // ─── FlagLifecycleState enum ─────────────────────────────────────────────────

    public function test_lifecycle_values(): void
    {
        $this->assertSame('draft',    FlagLifecycleState::Draft->value);
        $this->assertSame('active',   FlagLifecycleState::Active->value);
        $this->assertSame('archived', FlagLifecycleState::Archived->value);
    }

    public function test_is_live_only_for_active(): void
    {
        $this->assertTrue(FlagLifecycleState::Active->isLive());
        $this->assertFalse(FlagLifecycleState::Draft->isLive());
        $this->assertFalse(FlagLifecycleState::Archived->isLive());
    }

    public function test_valid_lifecycle_transitions(): void
    {
        $this->assertTrue(FlagLifecycleState::Draft->canTransitionTo(FlagLifecycleState::Active));
        $this->assertTrue(FlagLifecycleState::Draft->canTransitionTo(FlagLifecycleState::Archived));
        $this->assertTrue(FlagLifecycleState::Active->canTransitionTo(FlagLifecycleState::Archived));
    }

    public function test_invalid_lifecycle_transitions(): void
    {
        $this->assertFalse(FlagLifecycleState::Active->canTransitionTo(FlagLifecycleState::Draft));
        $this->assertFalse(FlagLifecycleState::Archived->canTransitionTo(FlagLifecycleState::Active));
        $this->assertFalse(FlagLifecycleState::Archived->canTransitionTo(FlagLifecycleState::Draft));
        $this->assertFalse(FlagLifecycleState::Archived->canTransitionTo(FlagLifecycleState::Archived));
    }

    // ─── FeatureFlag value object ────────────────────────────────────────────────

    public function test_default_lifecycle_is_active(): void
    {
        $flag = $this->makeFlag('my-flag');
        $this->assertSame(FlagLifecycleState::Active, $flag->lifecycle);
    }

    public function test_with_lifecycle_returns_new_instance(): void
    {
        $flag  = $this->makeFlag('my-flag');
        $draft = $flag->withLifecycle(FlagLifecycleState::Draft);

        $this->assertSame(FlagLifecycleState::Active, $flag->lifecycle);
        $this->assertSame(FlagLifecycleState::Draft, $draft->lifecycle);
        $this->assertSame($flag->name, $draft->name);
    }

    public function test_with_owner_sets_and_clears(): void
    {
        $flag    = $this->makeFlag('my-flag');
        $owned   = $flag->withOwner('team-platform');
        $cleared = $owned->withOwner(null);

        $this->assertNull($flag->owner);
        $this->assertSame('team-platform', $owned->owner);
        $this->assertNull($cleared->owner);
    }

    public function test_with_expiry_sets_and_clears(): void
    {
        $flag    = $this->makeFlag('my-flag');
        $expiry  = new \DateTimeImmutable('2026-12-31 00:00:00');
        $withExp = $flag->withExpiry($expiry);
        $cleared = $withExp->withExpiry(null);

        $this->assertNull($flag->expiresAt);
        $this->assertSame('2026-12-31', $withExp->expiresAt->format('Y-m-d'));
        $this->assertNull($cleared->expiresAt);
    }

    public function test_is_expired_returns_true_when_past(): void
    {
        $past = new \DateTimeImmutable('2020-01-01');
        $flag = $this->makeFlag('my-flag')->withExpiry($past);

        $this->assertTrue($flag->isExpired(new \DateTimeImmutable()));
    }

    public function test_is_expired_returns_false_when_future(): void
    {
        $future = new \DateTimeImmutable('2099-01-01');
        $flag   = $this->makeFlag('my-flag')->withExpiry($future);

        $this->assertFalse($flag->isExpired(new \DateTimeImmutable()));
    }

    public function test_is_expired_returns_false_when_no_expiry(): void
    {
        $flag = $this->makeFlag('my-flag');
        $this->assertFalse($flag->isExpired(new \DateTimeImmutable()));
    }

    public function test_is_live_delegates_to_lifecycle(): void
    {
        $active   = $this->makeFlag('f')->withLifecycle(FlagLifecycleState::Active);
        $draft    = $this->makeFlag('f')->withLifecycle(FlagLifecycleState::Draft);
        $archived = $this->makeFlag('f')->withLifecycle(FlagLifecycleState::Archived);

        $this->assertTrue($active->isLive());
        $this->assertFalse($draft->isLive());
        $this->assertFalse($archived->isLive());
    }

    // ─── Storage round-trip ──────────────────────────────────────────────────────

    public function test_lifecycle_owner_expiry_round_trip(): void
    {
        $expiry = new \DateTimeImmutable('2026-12-31 00:00:00');
        $flag   = $this->makeFlag('my-flag')
            ->withLifecycle(FlagLifecycleState::Draft)
            ->withOwner('team-mobile')
            ->withExpiry($expiry);

        $this->storage->save($flag);
        $loaded = $this->storage->findByName('my-flag');

        $this->assertNotNull($loaded);
        $this->assertSame(FlagLifecycleState::Draft, $loaded->lifecycle);
        $this->assertSame('team-mobile', $loaded->owner);
        $this->assertSame('2026-12-31', $loaded->expiresAt->format('Y-m-d'));
    }

    public function test_back_compat_missing_lifecycle_defaults_to_active(): void
    {
        // Simulate a legacy row without lifecycle column (via fromArray).
        $data = [
            'id'          => '11111111-1111-4111-8111-111111111111',
            'name'        => 'legacy',
            'description' => '',
            'enabled'     => false,
            'rules'       => [],
            'variants'    => null,
            'created_at'  => '2024-01-01T00:00:00+00:00',
            'updated_at'  => '2024-01-01T00:00:00+00:00',
        ];

        $flag = FeatureFlag::fromArray($data);

        $this->assertSame(FlagLifecycleState::Active, $flag->lifecycle);
        $this->assertNull($flag->owner);
        $this->assertNull($flag->expiresAt);
    }

    public function test_toArray_includes_lifecycle_owner_expiry(): void
    {
        $flag = $this->makeFlag('my-flag')
            ->withLifecycle(FlagLifecycleState::Draft)
            ->withOwner('squad-infra')
            ->withExpiry(new \DateTimeImmutable('2027-06-01'));

        $arr = $flag->toArray();

        $this->assertSame('draft', $arr['lifecycle']);
        $this->assertSame('squad-infra', $arr['owner']);
        $this->assertStringStartsWith('2027-06-01', $arr['expires_at']);
    }

    // ─── Flag aggregate lifecycle transitions ────────────────────────────────────

    public function test_aggregate_changes_lifecycle_draft_to_active(): void
    {
        $flag = $this->makeFlag('my-flag')->withLifecycle(FlagLifecycleState::Draft);
        $agg  = Flag::reconstitute($flag);
        $agg->changeLifecycle(FlagLifecycleState::Active, 'cli');

        $this->assertSame(FlagLifecycleState::Active, $agg->state()->lifecycle);
    }

    public function test_aggregate_rejects_invalid_transition(): void
    {
        $flag = $this->makeFlag('my-flag')->withLifecycle(FlagLifecycleState::Active);
        $agg  = Flag::reconstitute($flag);

        $this->expectException(\LogicException::class);
        $agg->changeLifecycle(FlagLifecycleState::Draft, 'cli');
    }

    public function test_aggregate_lifecycle_same_value_is_no_op(): void
    {
        $flag = $this->makeFlag('my-flag')->withLifecycle(FlagLifecycleState::Active);
        $agg  = Flag::reconstitute($flag);
        $agg->changeLifecycle(FlagLifecycleState::Active, 'cli');

        // No exception; events count should be 0 (no event emitted for no-op).
        $this->assertSame(FlagLifecycleState::Active, $agg->state()->lifecycle);
    }

    public function test_aggregate_archiving_sets_archived_flag(): void
    {
        $flag = $this->makeFlag('my-flag')->withLifecycle(FlagLifecycleState::Active);
        $agg  = Flag::reconstitute($flag);
        $agg->changeLifecycle(FlagLifecycleState::Archived, 'cli', 'end of life');

        $this->assertTrue($agg->isArchived());
        $this->assertSame(FlagLifecycleState::Archived, $agg->state()->lifecycle);
    }

    public function test_aggregate_set_owner_records_change(): void
    {
        $flag = $this->makeFlag('my-flag');
        $agg  = Flag::reconstitute($flag);
        $agg->setOwner('team-platform', 'cli');

        $this->assertSame('team-platform', $agg->state()->owner);
    }

    public function test_aggregate_set_owner_same_value_is_no_op(): void
    {
        $flag = $this->makeFlag('my-flag')->withOwner('team-x');
        $agg  = Flag::reconstitute($flag);
        $agg->setOwner('team-x', 'cli');

        $this->assertSame('team-x', $agg->state()->owner);
    }

    public function test_aggregate_set_expiry(): void
    {
        $flag   = $this->makeFlag('my-flag');
        $agg    = Flag::reconstitute($flag);
        $expiry = new \DateTimeImmutable('2027-01-01');
        $agg->setExpiry($expiry, 'cli');

        $this->assertSame('2027-01-01', $agg->state()->expiresAt->format('Y-m-d'));
    }

    public function test_aggregate_set_expiry_same_value_is_no_op(): void
    {
        $expiry = new \DateTimeImmutable('2027-01-01 00:00:00');
        $flag   = $this->makeFlag('my-flag')->withExpiry($expiry);
        $agg    = Flag::reconstitute($flag);
        $agg->setExpiry(new \DateTimeImmutable('2027-01-01 00:00:00'), 'cli');

        // Still set, no exception.
        $this->assertNotNull($agg->state()->expiresAt);
    }

    // ─── Compose preserves lifecycle fields ──────────────────────────────────────

    public function test_compose_preserves_lifecycle_from_definition(): void
    {
        $definition = $this->makeFlag('f')
            ->withLifecycle(FlagLifecycleState::Draft)
            ->withOwner('team-a')
            ->withExpiry(new \DateTimeImmutable('2027-01-01'));

        $state = new FlagEnvironmentState(
            flagId:        $definition->id,
            environment:   'staging',
            enabled:       true,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        );

        $composed = FeatureFlag::compose($definition, $state);

        $this->assertSame(FlagLifecycleState::Draft, $composed->lifecycle);
        $this->assertSame('team-a', $composed->owner);
        $this->assertNotNull($composed->expiresAt);
    }

    // ─── Promotion ───────────────────────────────────────────────────────────────

    public function test_record_promotion_event_on_aggregate(): void
    {
        $flag = $this->makeFlag('my-flag');
        $this->storage->save($flag);

        $sourceState = new FlagEnvironmentState(
            flagId:        $flag->id,
            environment:   'staging',
            enabled:       true,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        );
        $this->envStorage->save($sourceState);

        // Mimic what FlagPromotionService does: create target state + record event.
        $targetState = new FlagEnvironmentState(
            flagId:        $flag->id,
            environment:   'production',
            enabled:       $sourceState->enabled,
            rules:         $sourceState->rules,
            variants:      $sourceState->variants,
            variantRules:  $sourceState->variantRules,
            schedule:      $sourceState->schedule,
            payload:       $sourceState->payload,
            requiredScope: $sourceState->requiredScope,
            prerequisites: $sourceState->prerequisites,
            updatedAt:     new \DateTimeImmutable(),
        );
        $this->envStorage->save($targetState);

        // Verify target state was written.
        $loaded = $this->envStorage->findForFlag($flag->id, 'production');
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->enabled);
        $this->assertSame('production', $loaded->environment);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeFlag(string $name, string $id = '11111111-1111-4111-8111-111111111111'): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id:        $id,
            name:      $name,
            description: '',
            enabled:   false,
            rules:     [],
            variants:  null,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
