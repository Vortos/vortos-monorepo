<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;

/** @internal records events for assertions */
final class CollectingEventSink implements BackupEventSinkInterface
{
    /** @var list<BackupEvent> */
    public array $events = [];

    public function emit(BackupEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_map(static fn (BackupEvent $e): string => $e->type, $this->events);
    }

    public function last(): ?BackupEvent
    {
        return $this->events === [] ? null : $this->events[array_key_last($this->events)];
    }
}
