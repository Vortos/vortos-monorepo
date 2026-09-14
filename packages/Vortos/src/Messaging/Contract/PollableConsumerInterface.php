<?php

declare(strict_types=1);

namespace Vortos\Messaging\Contract;

/**
 * A consumer whose loop can be driven one iteration at a time.
 *
 * {@see ConsumerInterface::consume()} owns its loop and blocks until stopped, which ties one consumer
 * to one process. A worker running many low-traffic consumers that way pays for a whole PHP process —
 * a hundred megabytes and more at rest — per consumer, most of them idle for hours.
 *
 * Driving the loop from outside lets one process serve several consumers. Each keeps its own broker
 * subscription and consumer group, so group isolation is exactly what it was with one process each:
 * only the process is shared, never a group.
 *
 * Contract:
 *   open()      once, before the first poll — subscribes
 *   poll()      repeatedly — waits at most $timeoutMs, hands at most ONE message to $handler, so a
 *               busy consumer yields to the others after every message
 *   close()     once, after the last poll — final synchronous commit when draining
 *   isRunning() false once stopped, or once it has nothing more to do (an in-memory queue drained)
 *   hasFailed() true only when the broker stopped it — the case a shared process must not hide
 */
interface PollableConsumerInterface extends ConsumerInterface
{
    public function open(string $consumerName): void;

    /**
     * One iteration of the consume loop.
     *
     * @return bool whether a message was handed to $handler
     */
    public function poll(string $consumerName, callable $handler, int $timeoutMs): bool;

    public function close(string $consumerName): void;

    public function isRunning(): bool;

    /**
     * Whether this consumer stopped because of a broker failure rather than because it was asked to.
     *
     * In a process of its own a failed consumer exits and supervisor restarts it. In a shared process
     * the others would keep the process alive and the failed one would consume nothing, silently —
     * so the runner treats this as fatal for the whole process.
     */
    public function hasFailed(): bool;
}
