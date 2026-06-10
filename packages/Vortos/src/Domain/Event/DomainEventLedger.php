<?php

declare(strict_types=1);

namespace Vortos\Domain\Event;

/**
 * Process-local collection point for domain events recorded during a single
 * dispatch (command or consumed message).
 *
 * ## Why this exists
 *
 * Event dispatch used to depend on the command handler returning its
 * AggregateRoot — a handler returning void compiled, ran, and silently
 * dropped every recorded event. The ledger removes that failure mode
 * structurally: AggregateRoot::recordEvent() registers every envelope here,
 * and the bus that owns the transaction drains the ledger after the handler
 * returns — regardless of what the handler returned and regardless of how
 * many aggregates were touched.
 *
 * ## Scoping
 *
 * The owning bus brackets handler execution with open()/close(). Recording
 * only happens while a scope is open — aggregates used outside a managed
 * dispatch (unit tests, scripts) keep their local buffer behavior and never
 * touch process state. Scopes nest: only the root scope (open() returned
 * true) drains and dispatches; close() at root depth clears the buffer so
 * nothing leaks across requests/messages in long-lived workers (FrankenPHP
 * worker mode, Kafka consumers).
 *
 * ## Draining
 *
 * Drain in a loop until empty: dispatching an envelope can run in-process
 * event handlers synchronously, which may record follow-on events — those
 * land in the ledger mid-drain and must dispatch in the same transaction.
 *
 *   $isRoot = $ledger->open();
 *   try {
 *       $result = $handler($command);
 *       if ($isRoot) {
 *           while ($ledger->hasPending()) {
 *               foreach ($ledger->drain() as $envelope) {
 *                   $eventBus->dispatch($envelope);
 *               }
 *           }
 *       }
 *       return $result;
 *   } finally {
 *       $ledger->close();
 *   }
 *
 * Lives in the Domain layer (next to EventEnvelope) because AggregateRoot
 * writes to it directly — Domain cannot depend on Messaging.
 */
final class DomainEventLedger
{
    private static ?self $instance = null;

    /** @var EventEnvelope[] */
    private array $envelopes = [];

    private int $depth = 0;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Discards all state, including open scopes. Test isolation helper —
     * production code uses open()/close() bracketing instead.
     */
    public static function discard(): void
    {
        self::$instance = null;
    }

    /**
     * Opens a collection scope. Returns true if this is the root scope —
     * only the root scope drains and dispatches; nested scopes (a command
     * dispatched from inside another command's handler) collect into the
     * same buffer and let the root dispatch everything.
     */
    public function open(): bool
    {
        return ++$this->depth === 1;
    }

    /**
     * Closes the current scope. At root depth the buffer is cleared
     * unconditionally — on the success path drain() already emptied it;
     * on the failure path the transaction rolled back and the events
     * must not survive into the next dispatch.
     */
    public function close(): void
    {
        if ($this->depth > 0 && --$this->depth === 0) {
            $this->envelopes = [];
        }
    }

    /**
     * Registers a recorded envelope. No-op outside an open scope so that
     * aggregates exercised directly (unit tests, scripts) do not leak
     * process state.
     *
     * Called by AggregateRoot::recordEvent() — not by application code.
     */
    public function record(EventEnvelope $envelope): void
    {
        if ($this->depth > 0) {
            $this->envelopes[] = $envelope;
        }
    }

    public function hasPending(): bool
    {
        return $this->envelopes !== [];
    }

    /**
     * Returns all collected envelopes in recording order and clears the
     * buffer. Call in a loop with hasPending() — dispatching can record
     * follow-on events.
     *
     * @return EventEnvelope[]
     */
    public function drain(): array
    {
        $envelopes = $this->envelopes;
        $this->envelopes = [];
        return $envelopes;
    }
}
