<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Domain;

use PHPUnit\Framework\TestCase;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\FeatureFlags\Domain\Event\FlagArchivedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagCreatedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagDisabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagEnabledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRevertedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagRulesChangedEvent;
use Vortos\FeatureFlags\Domain\Event\FlagScheduledEvent;
use Vortos\FeatureFlags\Domain\Event\FlagVariantsChangedEvent;
use Vortos\FeatureFlags\Domain\Flag;
use Vortos\FeatureFlags\Exception\FlagArchivedException;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\RolloutSchedule;

final class FlagTest extends TestCase
{
    private const ID = '11111111-1111-4111-8111-111111111111';

    public function test_create_records_created_event_with_full_snapshot(): void
    {
        $flag   = Flag::create($this->state(name: 'dark-mode'), 'user-9', 'launch');
        $events = $this->pull($flag);

        $this->assertCount(1, $events);
        $created = $events[0];
        $this->assertInstanceOf(FlagCreatedEvent::class, $created);
        $this->assertSame('dark-mode', $created->name);
        $this->assertSame('user-9', $created->actorId);
        $this->assertSame('launch', $created->reason);
        $this->assertSame('dark-mode', $created->state['name']);
    }

    public function test_reconstitute_records_no_event(): void
    {
        $flag = Flag::reconstitute($this->state());
        $this->assertSame([], $flag->pullDomainEvents());
    }

    public function test_enable_disabled_flag_records_event_and_flips_state(): void
    {
        $flag = Flag::reconstitute($this->state(enabled: false));
        $flag->enable('actor-1');

        $events = $this->pull($flag);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(FlagEnabledEvent::class, $events[0]);
        $this->assertTrue($flag->state()->enabled);
    }

    public function test_enabling_already_enabled_flag_is_idempotent(): void
    {
        $flag = Flag::reconstitute($this->state(enabled: true));
        $flag->enable('actor-1');

        $this->assertSame([], $flag->pullDomainEvents());
    }

    public function test_disable_enabled_flag_records_event(): void
    {
        $flag = Flag::reconstitute($this->state(enabled: true));
        $flag->disable('actor-1', 'incident');

        $events = $this->pull($flag);
        $this->assertInstanceOf(FlagDisabledEvent::class, $events[0]);
        $this->assertSame('incident', $events[0]->reason);
        $this->assertFalse($flag->state()->enabled);
    }

    public function test_change_rules_records_old_and_new(): void
    {
        $flag     = Flag::reconstitute($this->state());
        $newRules = [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 25)];
        $flag->changeRules($newRules, 'actor-1');

        $events = $this->pull($flag);
        $this->assertInstanceOf(FlagRulesChangedEvent::class, $events[0]);
        $this->assertSame([], $events[0]->oldRules);
        $this->assertCount(1, $events[0]->newRules);
        $this->assertCount(1, $flag->state()->rules);
    }

    public function test_change_rules_to_identical_is_noop(): void
    {
        $rules = [new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 25)];
        $flag  = Flag::reconstitute($this->state(rules: $rules));
        $flag->changeRules([new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: 25)], 'actor-1');

        $this->assertSame([], $flag->pullDomainEvents());
    }

    public function test_change_variants_records_event(): void
    {
        $flag = Flag::reconstitute($this->state());
        $flag->changeVariants(['control' => 50, 'treatment' => 50], 'actor-1');

        $events = $this->pull($flag);
        $this->assertInstanceOf(FlagVariantsChangedEvent::class, $events[0]);
        $this->assertNull($events[0]->oldVariants);
        $this->assertSame(['control' => 50, 'treatment' => 50], $flag->state()->variants);
    }

    public function test_schedule_set_and_clear(): void
    {
        $flag     = Flag::reconstitute($this->state());
        $schedule = new RolloutSchedule(
            enableAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'),
        );
        $flag->schedule($schedule, 'actor-1');
        $set = $this->pull($flag);
        $this->assertInstanceOf(FlagScheduledEvent::class, $set[0]);
        $this->assertNotNull($set[0]->schedule);

        $flag->schedule(null, 'actor-1');
        $cleared = $this->pull($flag);
        $this->assertInstanceOf(FlagScheduledEvent::class, $cleared[0]);
        $this->assertNull($cleared[0]->schedule);
        $this->assertNull($flag->state()->schedule);
    }

    public function test_archive_records_final_state_and_blocks_further_mutation(): void
    {
        $flag = Flag::reconstitute($this->state(enabled: true, name: 'old-flag'));
        $flag->archive('actor-1', 'cleanup');

        $events = $this->pull($flag);
        $this->assertInstanceOf(FlagArchivedEvent::class, $events[0]);
        $this->assertSame('old-flag', $events[0]->finalState['name']);
        $this->assertTrue($flag->isArchived());

        $this->expectException(FlagArchivedException::class);
        $flag->enable('actor-1');
    }

    public function test_archiving_twice_is_idempotent(): void
    {
        $flag = Flag::reconstitute($this->state());
        $flag->archive('actor-1');
        $this->pull($flag);

        $flag->archive('actor-1');
        $this->assertSame([], $flag->pullDomainEvents());
    }

    public function test_revert_records_from_and_to_states(): void
    {
        $flag   = Flag::reconstitute($this->state(enabled: true));
        $target = $this->state(enabled: false);
        $flag->revertTo($target, 'actor-1', 'rollback');

        $events = $this->pull($flag);
        $this->assertInstanceOf(FlagRevertedEvent::class, $events[0]);
        $this->assertTrue($events[0]->fromState['enabled']);
        $this->assertFalse($events[0]->toState['enabled']);
        $this->assertFalse($flag->state()->enabled);
    }

    public function test_revert_to_identical_state_is_noop(): void
    {
        $state = $this->state(enabled: true);
        $flag  = Flag::reconstitute($state);
        $flag->revertTo($this->state(enabled: true), 'actor-1');

        $this->assertSame([], $flag->pullDomainEvents());
    }

    public function test_revert_to_different_flag_id_is_rejected(): void
    {
        $flag = Flag::reconstitute($this->state());
        $other = new FeatureFlag(
            '22222222-2222-4222-8222-222222222222',
            'other', '', true, [], null,
            new \DateTimeImmutable(), new \DateTimeImmutable(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $flag->revertTo($other, 'actor-1');
    }

    /**
     * @param FlagRule[]            $rules
     * @param array<string,int>|null $variants
     */
    private function state(bool $enabled = true, array $rules = [], ?array $variants = null, string $name = 'my-flag'): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag(self::ID, $name, '', $enabled, $rules, $variants, $now, $now);
    }

    /**
     * Pull recorded events and return the unwrapped payloads (in order).
     *
     * @return list<object>
     */
    private function pull(Flag $flag): array
    {
        return array_map(
            static fn(EventEnvelope $e) => $e->payload,
            $flag->pullDomainEvents(),
        );
    }
}
