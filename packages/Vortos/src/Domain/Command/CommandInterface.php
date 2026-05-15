<?php

declare(strict_types=1);

namespace Vortos\Domain\Command;

/**
 * Marker contract for all commands in the Vortos CQRS system.
 *
 * Commands express intent — they are named in the imperative mood
 * (RegisterUser, PlaceOrder, CancelBooking). They carry the data
 * needed to perform the operation and an idempotency key to prevent
 * duplicate execution on retry.
 *
 * Commands are dispatched through CommandBusInterface and handled
 * by exactly one CommandHandlerInterface implementation discovered
 * via the #[AsCommandHandler] attribute.
 *
 * Commands never return values — if you need a result, use a Query.
 */
interface CommandInterface
{
    /**
     * A unique key provided by the caller to prevent duplicate execution.
     * 
     * Clients (mobile apps, frontends) generate this key before sending
     * the command. If the same key is seen twice, the CommandBus returns
     * the previous result without re-executing the handler.
     * 
     * Use UuidV7 or a deterministic hash of the command's intent.
     * Never reuse keys across different commands.
     * 
     * Example: bin2hex(random_bytes(16)) generated client-side before submit.
     */
    public function idempotencyKey(): ?string;
}