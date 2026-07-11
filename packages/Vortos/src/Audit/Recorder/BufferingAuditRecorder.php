<?php

declare(strict_types=1);

namespace Vortos\Audit\Recorder;

use Vortos\Audit\Contract\AuditRecorderInterface;
use Vortos\Audit\Event\AuditEvent;

/**
 * In-memory recorder for tests and assertions. Keeps every recorded event so a test can
 * assert exactly what was audited without a database.
 */
final class BufferingAuditRecorder implements AuditRecorderInterface
{
    /** @var list<AuditEvent> */
    private array $events = [];

    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<AuditEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function last(): ?AuditEvent
    {
        return $this->events[array_key_last($this->events)] ?? null;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}
