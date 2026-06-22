<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Projection\FlagReadModelProjector;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;

/**
 * Block 10 projector tests: env-aware state view keying, audit log env column,
 * env isolation between independent state views, and replay == live invariant.
 */
final class FlagReadModelProjectorBlock10Test extends TestCase
{
    private const FLAG_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private B10AuditLog $audit;
    private B10StateView $state;
    private FlagReadModelProjector $projector;

    protected function setUp(): void
    {
        $this->audit     = new B10AuditLog();
        $this->state     = new B10StateView();
        $this->projector = new FlagReadModelProjector($this->audit, $this->state);
    }

    public function test_created_event_seeds_state_for_correct_env(): void
    {
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            'dark-mode',
            ['name' => 'dark-mode', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
            null,
            'staging', // environment
        )));

        $this->assertNull($this->state->findByName('dark-mode', 'production'), 'production state not created');
        $stagingView = $this->state->findByName('dark-mode', 'staging');
        $this->assertNotNull($stagingView);
        $this->assertSame('staging', $stagingView->environment);
        $this->assertFalse($stagingView->enabled);
    }

    public function test_enable_in_dev_does_not_affect_production(): void
    {
        $this->applyCreate('flag-x', 'production', false);
        $this->applyCreate('flag-x', 'dev', false);

        $this->projector->apply($this->envelope(new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'admin', null, 'dev')));

        $prod = $this->state->findByName('flag-x', 'production');
        $dev  = $this->state->findByName('flag-x', 'dev');

        $this->assertFalse($prod->enabled, 'enabling in dev must NOT affect production');
        $this->assertTrue($dev->enabled);
    }

    public function test_disable_in_production_does_not_affect_staging(): void
    {
        $this->applyCreate('flag-x', 'production', true);
        $this->applyCreate('flag-x', 'staging', true);

        $this->projector->apply($this->envelope(new FlagDisabledEvent(self::FLAG_ID, 'flag-x', 'admin', null, 'production')));

        $prod    = $this->state->findByName('flag-x', 'production');
        $staging = $this->state->findByName('flag-x', 'staging');

        $this->assertFalse($prod->enabled);
        $this->assertTrue($staging->enabled, 'disabling in production must NOT affect staging');
    }

    public function test_state_view_uses_compound_key_env_plus_name(): void
    {
        $this->applyCreate('same-flag', 'production', false);
        $this->applyCreate('same-flag', 'staging', true);

        $prodView    = $this->state->findByName('same-flag', 'production');
        $stagingView = $this->state->findByName('same-flag', 'staging');

        $this->assertNotSame($prodView, $stagingView, 'different views keyed by (env, name)');
        $this->assertFalse($prodView->enabled);
        $this->assertTrue($stagingView->enabled);
    }

    public function test_audit_entry_carries_environment(): void
    {
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            'flag-a',
            ['name' => 'flag-a', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
            null,
            'staging',
        )));

        $entries = $this->audit->findByFlag('flag-a');
        $this->assertCount(1, $entries);
        $this->assertSame('staging', $entries[0]->environment, 'audit entry must carry the event environment');
    }

    public function test_audit_default_environment_is_production_for_legacy_events(): void
    {
        // Event without explicit environment (legacy back-compat).
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            'legacy-flag',
            ['name' => 'legacy-flag', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
        )));

        $entries = $this->audit->findByFlag('legacy-flag');
        $this->assertSame('production', $entries[0]->environment);
    }

    public function test_replay_matches_live_per_environment(): void
    {
        $events = [
            new FlagCreatedEvent(self::FLAG_ID, 'flag-x', ['name' => 'flag-x', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []], 'a', null, 'staging'),
            new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'a', null, 'staging'),
            new FlagRulesChangedEvent(self::FLAG_ID, 'flag-x', [], [['type' => 'percentage', 'percentage' => 20]], 'a', null, 'staging'),
        ];

        foreach ($events as $i => $event) {
            $this->projector->apply($this->envelope($event, eventId: "live-$i"));
        }
        $live = $this->state->findByName('flag-x', 'staging');

        // Replay into fresh projectors.
        $replayAudit = new B10AuditLog();
        $replayState = new B10StateView();
        $replay      = new FlagReadModelProjector($replayAudit, $replayState);
        foreach ($events as $i => $event) {
            $replay->apply($this->envelope($event, eventId: "replay-$i"));
        }
        $replayed = $replayState->findByName('flag-x', 'staging');

        $this->assertTrue($live->enabled);
        $this->assertSame(1, $live->ruleCount);
        $this->assertSame($live->enabled, $replayed->enabled);
        $this->assertSame($live->ruleCount, $replayed->ruleCount);
        $this->assertSame($live->environment, $replayed->environment);
    }

    public function test_projection_is_idempotent_per_env(): void
    {
        $event    = new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'admin', null, 'production');
        $envelope = $this->envelope($event, eventId: 'fixed-id');

        $this->applyCreate('flag-x', 'production', false);
        $this->projector->apply($envelope);
        $this->projector->apply($envelope); // re-delivery

        $this->assertSame(1, $this->audit->countForEvent('fixed-id'), 'audit must be idempotent');
        $this->assertTrue($this->state->findByName('flag-x', 'production')->enabled);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function applyCreate(string $name, string $environment, bool $enabled): void
    {
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            $name,
            ['name' => $name, 'enabled' => $enabled, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
            null,
            $environment,
        )));
    }

    private function envelope(object $payload, string $eventId = 'evt-1'): EventEnvelope
    {
        return new EventEnvelope(
            eventId:          $eventId,
            aggregateId:      self::FLAG_ID,
            aggregateType:    'Flag',
            aggregateVersion: 1,
            payloadType:      $payload::class,
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable('2026-06-21T10:00:00Z'),
            payload:          $payload,
            metadata:         Metadata::empty(),
        );
    }
}

/** @internal */
final class B10AuditLog implements FlagAuditLogRepositoryInterface
{
    /** @var array<string,FlagAuditEntry> */
    private array $entries = [];

    public function upsert(FlagAuditEntry $entry): void
    {
        $this->entries[$entry->eventId] = $entry;
    }

    public function findByFlag(string $flagName, int $limit = 100): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn(FlagAuditEntry $e) => $e->flagName === $flagName,
        ));
    }

    public function countForEvent(string $eventId): int
    {
        return isset($this->entries[$eventId]) ? 1 : 0;
    }

    public function stream(\Vortos\FeatureFlags\Compliance\Export\AuditExportFilter $filter): \Generator
    {
        foreach ($this->entries as $entry) {
            if ($filter->matches($entry)) {
                yield $entry;
            }
        }
    }
}

/** @internal */
final class B10StateView implements FlagStateViewRepositoryInterface
{
    /** @var array<string,FlagStateView> keyed by "{env}:{name}" */
    private array $views = [];

    public function upsert(FlagStateView $view): void
    {
        $this->views[$view->compoundKey()] = $view;
    }

    public function findByName(string $flagName, string $environment = 'production'): ?FlagStateView
    {
        return $this->views[$environment . ':' . $flagName] ?? null;
    }

    public function all(string $environment = 'production', int $limit = 500): array
    {
        return array_values(array_filter(
            $this->views,
            static fn(FlagStateView $v) => $v->environment === $environment,
        ));
    }
}
