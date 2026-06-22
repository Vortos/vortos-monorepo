<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Projection\FlagReadModelProjector;
use Vortos\FeatureFlags\ReadModel\FlagAuditEntry;
use Vortos\FeatureFlags\ReadModel\FlagAuditLogRepositoryInterface;
use Vortos\FeatureFlags\ReadModel\FlagStateView;
use Vortos\FeatureFlags\ReadModel\FlagStateViewRepositoryInterface;

final class FlagReadModelProjectorTest extends TestCase
{
    private const FLAG_ID = '11111111-1111-4111-8111-111111111111';

    private InMemoryAuditLog $audit;
    private InMemoryStateView $state;
    private FlagReadModelProjector $projector;

    protected function setUp(): void
    {
        $this->audit     = new InMemoryAuditLog();
        $this->state     = new InMemoryStateView();
        $this->projector = new FlagReadModelProjector($this->audit, $this->state);
    }

    public function test_created_event_seeds_audit_and_state(): void
    {
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            'dark-mode',
            ['name' => 'dark-mode', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => [], 'variants' => null, 'schedule' => null],
            'admin-1',
            'launch',
        )));

        $entries = $this->audit->findByFlag('dark-mode');
        $this->assertCount(1, $entries);
        $this->assertSame('FlagCreatedEvent', $entries[0]->eventType);
        $this->assertSame('admin-1', $entries[0]->actorId);

        $view = $this->state->findByName('dark-mode');
        $this->assertNotNull($view);
        $this->assertFalse($view->enabled);
        $this->assertFalse($view->archived);
    }

    public function test_enable_then_disable_updates_state(): void
    {
        $this->applyCreate('flag-x', enabled: false);

        $this->projector->apply($this->envelope(new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'admin-1')));
        $this->assertTrue($this->state->findByName('flag-x')->enabled);

        $this->projector->apply($this->envelope(new FlagDisabledEvent(self::FLAG_ID, 'flag-x', 'admin-1')));
        $this->assertFalse($this->state->findByName('flag-x')->enabled);
    }

    public function test_rules_changed_updates_rule_count(): void
    {
        $this->applyCreate('flag-x', enabled: true);

        $this->projector->apply($this->envelope(new FlagRulesChangedEvent(
            self::FLAG_ID,
            'flag-x',
            [],
            [['type' => 'percentage', 'percentage' => 10], ['type' => 'users', 'users' => ['a']]],
            'admin-1',
        )));

        $this->assertSame(2, $this->state->findByName('flag-x')->ruleCount);
    }

    public function test_archive_marks_state_archived(): void
    {
        $this->applyCreate('flag-x', enabled: true);

        $this->projector->apply($this->envelope(new FlagArchivedEvent(
            self::FLAG_ID,
            'flag-x',
            ['name' => 'flag-x', 'enabled' => true],
            'admin-1',
            'cleanup',
        )));

        $this->assertTrue($this->state->findByName('flag-x')->archived);
    }

    public function test_projection_is_idempotent_on_redelivery(): void
    {
        $event = new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'admin-1');
        $this->applyCreate('flag-x', enabled: false);

        $envelope = $this->envelope($event, eventId: 'fixed-event-id');
        $this->projector->apply($envelope);
        $this->projector->apply($envelope); // re-delivery

        // Same event id upserts the same audit row — not duplicated.
        $this->assertSame(1, $this->audit->countForEvent('fixed-event-id'));
        $this->assertTrue($this->state->findByName('flag-x')->enabled);
    }

    public function test_replay_reproduces_the_same_state_as_live(): void
    {
        $events = [
            new FlagCreatedEvent(self::FLAG_ID, 'flag-x', ['name' => 'flag-x', 'enabled' => false, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []], 'a'),
            new FlagEnabledEvent(self::FLAG_ID, 'flag-x', 'a'),
            new FlagRulesChangedEvent(self::FLAG_ID, 'flag-x', [], [['type' => 'percentage', 'percentage' => 50]], 'a'),
            new FlagDisabledEvent(self::FLAG_ID, 'flag-x', 'a'),
        ];

        foreach ($events as $i => $event) {
            $this->projector->apply($this->envelope($event, eventId: "live-$i"));
        }
        $live = $this->state->findByName('flag-x');

        // Replay into a fresh projector.
        $replayAudit = new InMemoryAuditLog();
        $replayState = new InMemoryStateView();
        $replay      = new FlagReadModelProjector($replayAudit, $replayState);
        foreach ($events as $i => $event) {
            $replay->apply($this->envelope($event, eventId: "replay-$i"));
        }
        $replayed = $replayState->findByName('flag-x');

        $this->assertFalse($live->enabled);
        $this->assertSame(1, $live->ruleCount);
        $this->assertSame($live->enabled, $replayed->enabled);
        $this->assertSame($live->ruleCount, $replayed->ruleCount);
        $this->assertSame($live->archived, $replayed->archived);
    }

    private function applyCreate(string $name, bool $enabled): void
    {
        $this->projector->apply($this->envelope(new FlagCreatedEvent(
            self::FLAG_ID,
            $name,
            ['name' => $name, 'enabled' => $enabled, 'value_type' => 'bool', 'kind' => 'release', 'rules' => []],
            'admin-1',
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

/** @internal test double */
final class InMemoryAuditLog implements FlagAuditLogRepositoryInterface
{
    /** @var array<string,FlagAuditEntry> keyed by event id */
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

/** @internal test double */
final class InMemoryStateView implements FlagStateViewRepositoryInterface
{
    /** @var array<string,FlagStateView> keyed by compoundKey "{env}:{flagName}" */
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
