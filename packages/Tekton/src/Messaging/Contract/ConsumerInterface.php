<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Contract;

/**
 * Consumes messages from a named consumer pipeline.
 *
 * Implementations are broker-specific. The consumer loop is owned here —
 * it polls the broker, calls the handler callable for each message,
 * and manages acknowledgement. The ConsumerRunner provides the handler callable.
 */
interface ConsumerInterface
{
    /**
     * Start the consumer loop for the named consumer.
     * Blocks until stop() is called or a fatal error occurs.
     * The $handler callable receives a ReceivedMessage and is responsible
     * for deserialization, handler dispatch, and error handling.
     */
    public function consume(string $consumerName, callable $handler):void;

    /**
     * Signal the consumer loop to exit gracefully after the current message.
     * Called by signal handlers (SIGTERM, SIGINT) in the CLI command.
     */
    public function stop():void;

    /**
     * Acknowledge successful processing of a message.
     * For Kafka: commits the offset. For others: sends ack to broker.
     * Only called after all handlers for the message have succeeded.
     */
    public function acknowledge(ReceivedMessage $message):void;

    /**
     * Reject a message that could not be processed.
     * If $requeue is true, the message will be redelivered.
     * If false, it is discarded (dead-lettered by the ConsumerRunner).
     */
    public function reject(ReceivedMessage $message, bool $requeue = false):void;
}