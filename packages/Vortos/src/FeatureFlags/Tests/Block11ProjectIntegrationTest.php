<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagEnvironmentState;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\Project;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Resolution\EnvironmentScopedFlagResolver;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\SegmentRegistry;
use Vortos\FeatureFlags\Storage\DatabaseFlagEnvironmentStateStorage;
use Vortos\FeatureFlags\Storage\DatabaseFlagStorage;
use Vortos\FeatureFlags\Storage\DatabaseProjectStorage;
use Vortos\FeatureFlags\Storage\DatabaseSegmentStorage;

/**
 * End-to-end project isolation tests (Block 11).
 *
 * Validates that flags and segments are properly scoped to projects, that
 * cross-project access is blocked at resolver/registry level, and that
 * back-compat (default project) works for legacy flags.
 */
final class Block11ProjectIntegrationTest extends TestCase
{
    private const FLAG_TABLE    = 'feature_flags';
    private const ENV_TABLE     = 'feature_flag_environment_state';
    private const SEGMENT_TABLE = 'feature_flag_segments';
    private const PROJECT_TABLE = 'feature_flag_projects';

    private \Doctrine\DBAL\Connection $connection;
    private DatabaseFlagStorage $flagStorage;
    private DatabaseFlagEnvironmentStateStorage $envStorage;
    private DatabaseSegmentStorage $segmentStorage;
    private DatabaseProjectStorage $projectStorage;
    private FlagScopeContext $scope;
    private ProjectContext $projectCtx;
    private EnvironmentScopedFlagResolver $resolver;
    private SegmentRegistry $segmentRegistry;

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

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::SEGMENT_TABLE . ' (
                id          VARCHAR(36)  NOT NULL,
                name        VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                rules       TEXT NOT NULL DEFAULT \'[]\',
                project_id  VARCHAR(191) NOT NULL DEFAULT \'default\',
                created_at  DATETIME NOT NULL,
                updated_at  DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->connection->executeStatement(
            'CREATE TABLE ' . self::PROJECT_TABLE . ' (
                id          VARCHAR(36)  NOT NULL,
                name        VARCHAR(191) NOT NULL,
                slug        VARCHAR(191) NOT NULL UNIQUE,
                description TEXT,
                created_at  DATETIME NOT NULL,
                updated_at  DATETIME NOT NULL,
                PRIMARY KEY (id)
            )',
        );

        $this->flagStorage    = new DatabaseFlagStorage($this->connection, self::FLAG_TABLE);
        $this->envStorage     = new DatabaseFlagEnvironmentStateStorage($this->connection, self::ENV_TABLE);
        $this->segmentStorage = new DatabaseSegmentStorage($this->connection, self::SEGMENT_TABLE);
        $this->projectStorage = new DatabaseProjectStorage($this->connection, self::PROJECT_TABLE);
        $this->scope          = new FlagScopeContext();
        $this->projectCtx     = new ProjectContext();
        $this->resolver       = new EnvironmentScopedFlagResolver(
            $this->flagStorage,
            $this->envStorage,
            $this->scope,
            $this->projectCtx,
        );
        $this->segmentRegistry = new SegmentRegistry($this->segmentStorage, $this->projectCtx);

        // Default production scope.
        $this->scope->withEnvironment('production');
    }

    // ─── Project CRUD ────────────────────────────────────────────────────────────

    public function test_project_slugify(): void
    {
        $this->assertSame('mobile-app', Project::slugify('Mobile App'));
        $this->assertSame('my-app', Project::slugify('  My  App  '));
        $this->assertSame('web-v2', Project::slugify('Web-V2'));
    }

    public function test_project_round_trips_through_storage(): void
    {
        $now     = new \DateTimeImmutable();
        $project = new Project(
            id:          '11111111-1111-4111-8111-111111111111',
            name:        'Mobile App',
            slug:        'mobile-app',
            description: 'Our mobile flags',
            createdAt:   $now,
            updatedAt:   $now,
        );

        $this->projectStorage->save($project);
        $loaded = $this->projectStorage->findBySlug('mobile-app');

        $this->assertNotNull($loaded);
        $this->assertSame('mobile-app', $loaded->slug);
        $this->assertSame('Mobile App', $loaded->name);
    }

    // ─── Flag project isolation ──────────────────────────────────────────────────

    public function test_resolver_only_returns_flags_for_active_project(): void
    {
        // Save two flags in different projects.
        $flagA = $this->makeFlag('flag-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $flagB = $this->makeFlag('flag-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $this->flagStorage->save($flagA);
        $this->flagStorage->save($flagB);
        $this->saveEnvState($flagA->id, 'production', true);
        $this->saveEnvState($flagB->id, 'production', true);

        $ctx = new FlagContext('u1');

        $this->projectCtx->withProject('project-a');
        $aFlag = $this->resolver->resolve('flag-a', $ctx);
        $bFlag = $this->resolver->resolve('flag-b', $ctx);

        $this->assertNotNull($aFlag);
        $this->assertNull($bFlag, 'flag-b must not be visible in project-a context');
    }

    public function test_resolve_all_filters_by_project(): void
    {
        $flagA = $this->makeFlag('flag-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $flagB = $this->makeFlag('flag-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $flagC = $this->makeFlag('flag-c', '33333333-3333-4333-8333-333333333333', 'project-a');
        $this->flagStorage->save($flagA);
        $this->flagStorage->save($flagB);
        $this->flagStorage->save($flagC);
        $this->saveEnvState($flagA->id, 'production', true);
        $this->saveEnvState($flagB->id, 'production', true);
        $this->saveEnvState($flagC->id, 'production', false);

        $this->projectCtx->withProject('project-a');
        $all = $this->resolver->resolveAll(new FlagContext('u1'));

        $names = array_column($all, 'name');
        sort($names);
        $this->assertSame(['flag-a', 'flag-c'], $names);
        $this->assertNotContains('flag-b', $names);
    }

    public function test_memo_invalidated_when_project_switches(): void
    {
        $flagA = $this->makeFlag('flag-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $flagB = $this->makeFlag('flag-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $this->flagStorage->save($flagA);
        $this->flagStorage->save($flagB);
        $this->saveEnvState($flagA->id, 'production', true);
        $this->saveEnvState($flagB->id, 'production', true);

        $ctx = new FlagContext('u1');

        $this->projectCtx->withProject('project-a');
        $allA = $this->resolver->resolveAll($ctx);

        $this->projectCtx->withProject('project-b');
        $this->resolver->reset();
        $allB = $this->resolver->resolveAll($ctx);

        $namesA = array_column($allA, 'name');
        $namesB = array_column($allB, 'name');

        $this->assertContains('flag-a', $namesA);
        $this->assertNotContains('flag-b', $namesA);

        $this->assertContains('flag-b', $namesB);
        $this->assertNotContains('flag-a', $namesB);
    }

    public function test_default_project_sees_legacy_flags(): void
    {
        // A flag with no explicit project_id (back-compat: defaults to 'default').
        $flag = $this->makeFlag('legacy', '11111111-1111-4111-8111-111111111111', 'default');
        $this->flagStorage->save($flag);
        $this->saveEnvState($flag->id, 'production', true);

        $this->projectCtx->withProject('default');
        $resolved = $this->resolver->resolve('legacy', new FlagContext('u1'));

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->enabled);
    }

    public function test_project_context_run_as_isolates_within_scope(): void
    {
        $flagA = $this->makeFlag('flag-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $flagB = $this->makeFlag('flag-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $this->flagStorage->save($flagA);
        $this->flagStorage->save($flagB);
        $this->saveEnvState($flagA->id, 'production', true);
        $this->saveEnvState($flagB->id, 'production', true);

        $this->projectCtx->withProject('project-a');
        $ctx = new FlagContext('u1');

        $bInB = $this->projectCtx->runAs('project-b', function () use ($ctx): bool {
            $this->resolver->reset();
            return $this->resolver->resolve('flag-b', $ctx) !== null;
        });

        $this->resolver->reset();
        $aInA = $this->resolver->resolve('flag-a', $ctx) !== null;

        $this->assertTrue($bInB);
        $this->assertTrue($aInA);
        $this->assertSame('project-a', $this->projectCtx->projectId(), 'runAs must restore project-a');
    }

    // ─── Segment project isolation ───────────────────────────────────────────────

    public function test_segment_registry_filters_by_project(): void
    {
        $segA = $this->makeSegment('seg-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $segB = $this->makeSegment('seg-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $this->segmentStorage->save($segA);
        $this->segmentStorage->save($segB);

        $this->projectCtx->withProject('project-a');
        $found  = $this->segmentRegistry->resolve('seg-a');
        $notFound = $this->segmentRegistry->resolve('seg-b');

        $this->assertNotNull($found);
        $this->assertNull($notFound, 'seg-b must not be visible in project-a context');
    }

    public function test_segment_registry_memo_resets_on_project_switch(): void
    {
        $segA = $this->makeSegment('seg-a', '11111111-1111-4111-8111-111111111111', 'project-a');
        $segB = $this->makeSegment('seg-b', '22222222-2222-4222-8222-222222222222', 'project-b');
        $this->segmentStorage->save($segA);
        $this->segmentStorage->save($segB);

        $this->projectCtx->withProject('project-a');
        $this->segmentRegistry->resolve('seg-a');  // prime memo

        $this->projectCtx->withProject('project-b');
        $found = $this->segmentRegistry->resolve('seg-b');

        $this->assertNotNull($found, 'switching project must re-query and find seg-b');
    }

    // ─── Flag project_id persists through storage round-trip ────────────────────

    public function test_flag_project_id_persists_through_storage(): void
    {
        $flag = $this->makeFlag('my-flag', '11111111-1111-4111-8111-111111111111', 'mobile-app');
        $this->flagStorage->save($flag);

        $loaded = $this->flagStorage->findByName('my-flag');

        $this->assertNotNull($loaded);
        $this->assertSame('mobile-app', $loaded->projectId);
    }

    public function test_segment_project_id_persists_through_storage(): void
    {
        $seg = $this->makeSegment('my-seg', '11111111-1111-4111-8111-111111111111', 'mobile-app');
        $this->segmentStorage->save($seg);

        $loaded = $this->segmentStorage->findByName('my-seg');

        $this->assertNotNull($loaded);
        $this->assertSame('mobile-app', $loaded->projectId);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function makeFlag(string $name, string $id, string $projectId = 'default'): FeatureFlag
    {
        $now = new \DateTimeImmutable();
        return new FeatureFlag(
            id:          $id,
            name:        $name,
            description: '',
            enabled:     false,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
            projectId:   $projectId,
        );
    }

    private function makeSegment(string $name, string $id, string $projectId = 'default'): Segment
    {
        $now = new \DateTimeImmutable();
        return new Segment(
            id:          $id,
            name:        $name,
            description: '',
            rules:       [],
            createdAt:   $now,
            updatedAt:   $now,
            projectId:   $projectId,
        );
    }

    private function saveEnvState(string $flagId, string $env, bool $enabled): void
    {
        $this->envStorage->save(new FlagEnvironmentState(
            flagId:        $flagId,
            environment:   $env,
            enabled:       $enabled,
            rules:         [],
            variants:      null,
            variantRules:  null,
            schedule:      null,
            payload:       null,
            requiredScope: null,
            prerequisites: [],
            updatedAt:     new \DateTimeImmutable(),
        ));
    }
}
