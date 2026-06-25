<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Event;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Event\CompositeBackupEventSink;
use Vortos\Backup\Tests\Support\CollectingEventSink;

final class CompositeBackupEventSinkTest extends TestCase
{
    public function test_fans_out_to_all_sinks(): void
    {
        $a = new CollectingEventSink();
        $b = new CollectingEventSink();
        $composite = new CompositeBackupEventSink([$a, $b]);

        $composite->emit($this->event());

        $this->assertCount(1, $a->events);
        $this->assertCount(1, $b->events);
    }

    public function test_a_throwing_sink_does_not_break_fan_out(): void
    {
        $throwing = new class implements BackupEventSinkInterface {
            public function emit(BackupEvent $event): void
            {
                throw new RuntimeException('alerter down');
            }
        };
        $good = new CollectingEventSink();

        $composite = new CompositeBackupEventSink([$throwing, $good]);
        $composite->emit($this->event()); // must not throw

        $this->assertCount(1, $good->events, 'A broken sink must never stop the others.');
    }

    private function event(): BackupEvent
    {
        return BackupEvent::failed(DatabaseEngine::Postgres, 'prod', 'boom', new DateTimeImmutable('now'));
    }
}
