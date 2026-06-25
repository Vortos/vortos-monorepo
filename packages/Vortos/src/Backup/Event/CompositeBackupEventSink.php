<?php

declare(strict_types=1);

namespace Vortos\Backup\Event;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Fans one event out to every registered sink, isolating failures: if one sink
 * throws, the others still receive the event and the backup is never affected.
 *
 * This is what lets Block 17 register an alerting sink alongside the default logging
 * sink with no risk that a paging outage breaks backups.
 */
final class CompositeBackupEventSink implements BackupEventSinkInterface
{
    /** @var list<BackupEventSinkInterface> */
    private array $sinks;

    /**
     * @param iterable<BackupEventSinkInterface> $sinks
     */
    public function __construct(iterable $sinks, private readonly LoggerInterface $logger = new NullLogger())
    {
        $this->sinks = is_array($sinks) ? array_values($sinks) : iterator_to_array($sinks, false);
    }

    public function emit(BackupEvent $event): void
    {
        foreach ($this->sinks as $sink) {
            try {
                $sink->emit($event);
            } catch (Throwable $e) {
                // A failing sink must never break a backup or the fan-out.
                $this->logger->error('Backup event sink threw; continuing fan-out.', [
                    'sink' => $sink::class,
                    'event_type' => $event->type,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }
}
