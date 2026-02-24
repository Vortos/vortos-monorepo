<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messaging\Middleware\Consumer;

use Doctrine\DBAL\Connection;
use Fortizan\Tekton\Messaging\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Envelope;
use Throwable;

/**
 * Wraps handler execution in a database transaction.
 * 
 * On success: commits the transaction after all handlers complete.
 * On failure: rolls back the transaction and rethrows the exception.
 * 
 * Register this middleware on consumers that need transactional consistency
 * between handler side effects and domain state. Do NOT register this globally
 * on the bus — only on consumers where DB writes occur in handlers.
 * 
 * Priority should be lower than TracingMiddleware and LoggingMiddleware
 * so tracing and logging capture the full execution including transaction overhead.
 */
final class TransactionalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Connection $connection
    ){
    }

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        $this->connection->beginTransaction();
        
        try {
            $result = $next($envelope);

            $this->connection->commit();

            return $result;
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}