<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Dedupe\DedupeDecision;
use Vortos\Alerts\DispatchResult;
use Vortos\Alerts\Event\AlertEvent;
use Vortos\Alerts\Integration\Messaging\QueueBacklog;
use Vortos\Alerts\Integration\Messaging\QueueBacklogAlertSource;
use Vortos\Alerts\Integration\Messaging\QueueBacklogProviderInterface;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\ThresholdCondition;
use Vortos\Alerts\Rule\Condition\ThresholdOperator;
use Vortos\Alerts\Severity;

final class QueueBacklogAlertSourceTest extends TestCase
{
    public function test_fires_when_dlq_depth_exceeds_the_threshold(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->source($dispatcher, [new QueueBacklog('dlq:kafka', 3)], $this->depthRule())
            ->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->events);
        self::assertSame('dlq:kafka', $dispatcher->events[0]->labels['queue']);
    }

    public function test_stays_quiet_when_the_queue_is_empty(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->source($dispatcher, [new QueueBacklog('dlq:kafka', 0)], $this->depthRule())
            ->tick('prod', new DateTimeImmutable());

        self::assertSame([], $dispatcher->events);
    }

    public function test_each_queue_gets_its_own_event_so_one_does_not_mask_another(): void
    {
        $dispatcher = new RecordingDispatcher();

        $this->source(
            $dispatcher,
            [new QueueBacklog('dlq:kafka', 5), new QueueBacklog('dlq:sqs', 9)],
            $this->depthRule(),
        )->tick('prod', new DateTimeImmutable());

        self::assertSame(
            ['dlq:kafka', 'dlq:sqs'],
            array_map(static fn (AlertEvent $e) => $e->labels['queue'], $dispatcher->events),
        );
    }

    public function test_a_rule_scoped_to_one_queue_ignores_the_others(): void
    {
        $dispatcher = new RecordingDispatcher();

        $rule = new AlertRule(
            id: 'dlq-kafka-only',
            severity: Severity::Critical,
            kind: AlertRuleKind::QueueLag,
            condition: new ThresholdCondition(ThresholdOperator::GreaterThan, 0.0),
            labels: ['queue' => 'dlq:kafka'],
        );

        $this->source(
            $dispatcher,
            [new QueueBacklog('dlq:kafka', 5), new QueueBacklog('dlq:sqs', 99)],
            $rule,
        )->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->events);
        self::assertSame('dlq:kafka', $dispatcher->events[0]->labels['queue']);
    }

    public function test_oldest_age_measure_catches_a_stuck_queue_that_depth_would_miss(): void
    {
        $dispatcher = new RecordingDispatcher();

        $rule = new AlertRule(
            id: 'outbox-stuck',
            severity: Severity::Critical,
            kind: AlertRuleKind::QueueLag,
            // Shallow but ancient: depth 3 would pass any sane depth threshold.
            condition: new ThresholdCondition(ThresholdOperator::GreaterThan, 900.0),
            labels: ['measure' => 'oldest_age_seconds'],
        );

        $this->source(
            $dispatcher,
            [new QueueBacklog('outbox:kafka', 3, oldestAgeSeconds: 21_600)],
            $rule,
        )->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->events);
    }

    public function test_a_missing_age_reading_is_skipped_rather_than_evaluated_as_zero(): void
    {
        $dispatcher = new RecordingDispatcher();

        $rule = new AlertRule(
            id: 'outbox-stuck',
            severity: Severity::Critical,
            kind: AlertRuleKind::QueueLag,
            condition: new ThresholdCondition(ThresholdOperator::LessThan, 900.0),
            labels: ['measure' => 'oldest_age_seconds'],
        );

        $this->source(
            $dispatcher,
            [new QueueBacklog('outbox:kafka', 0, oldestAgeSeconds: null)],
            $rule,
        )->tick('prod', new DateTimeImmutable());

        self::assertSame(
            [],
            $dispatcher->events,
            'An empty queue has no oldest-age reading; evaluating 0 would fire a "less than" rule spuriously.',
        );
    }

    public function test_rules_of_other_kinds_are_ignored(): void
    {
        $dispatcher = new RecordingDispatcher();

        $rule = new AlertRule(
            id: 'http-errors',
            severity: Severity::Critical,
            kind: AlertRuleKind::ErrorRate,
            condition: new ThresholdCondition(ThresholdOperator::GreaterThan, 0.0),
        );

        $this->source($dispatcher, [new QueueBacklog('dlq:kafka', 50)], $rule)
            ->tick('prod', new DateTimeImmutable());

        self::assertSame([], $dispatcher->events);
    }

    private function depthRule(): AlertRule
    {
        return new AlertRule(
            id: 'dlq-not-empty',
            severity: Severity::Critical,
            kind: AlertRuleKind::QueueLag,
            condition: new ThresholdCondition(ThresholdOperator::GreaterThan, 0.0),
        );
    }

    /** @param list<QueueBacklog> $backlogs */
    private function source(
        RecordingDispatcher $dispatcher,
        array $backlogs,
        AlertRule $rule,
    ): QueueBacklogAlertSource {
        return new QueueBacklogAlertSource(
            [new FixedBacklogProvider($backlogs)],
            new AlertRuleSet([$rule]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );
    }
}

final class FixedBacklogProvider implements QueueBacklogProviderInterface
{
    /** @param list<QueueBacklog> $backlogs */
    public function __construct(private readonly array $backlogs) {}

    public function name(): string
    {
        return 'test';
    }

    public function backlogs(): array
    {
        return $this->backlogs;
    }
}

final class RecordingDispatcher implements AlertDispatcherInterface
{
    /** @var list<AlertEvent> */
    public array $events = [];

    public function dispatch(AlertEvent $event, ?array $routingOverride = null): DispatchResult
    {
        $this->events[] = $event;

        return new DispatchResult(DedupeDecision::New, []);
    }
}
