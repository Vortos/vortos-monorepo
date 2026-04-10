<?php

// declare(strict_types=1);

// namespace Vortos\Messaging\Middleware\Consumer;

// use Doctrine\DBAL\Connection;
// use Vortos\Messaging\Middleware\MiddlewareInterface;
// use Symfony\Component\Messenger\Envelope;
// use Throwable;

// /**
//  * Wraps handler execution in a database transaction.
//  * 
//  * On success: commits the transaction after all handlers complete.
//  * On failure: rolls back the transaction and rethrows the exception.
//  * 
//  * Register this middleware on consumers that need transactional consistency
//  * between handler side effects and domain state. Do NOT register this globally
//  * on the bus — only on consumers where DB writes occur in handlers.
//  * 
//  * Priority should be lower than TracingMiddleware and LoggingMiddleware
//  * so tracing and logging capture the full execution including transaction overhead.
//  */
// final class TransactionalMiddleware implements MiddlewareInterface
// {
//     public function __construct(
//         private Connection $connection
//     ){
//     }

//     public function handle(Envelope $envelope, callable $next): Envelope
//     {
//         $this->connection->beginTransaction();
//         try {
//             $result = $next($envelope);
//             if ($this->connection->isTransactionActive()) {
//                 $this->connection->commit();
//             }
//             return $result;
//         } catch (Throwable $e) {
//             if ($this->connection->isTransactionActive()) {
//                 $this->connection->rollBack();
//             }
//             throw $e;
//         }
//     }
// }

declare(strict_types=1);
namespace Vortos\Messaging\Middleware\Consumer;

use Symfony\Component\Messenger\Envelope;
use Vortos\Messaging\Middleware\MiddlewareInterface;
use Vortos\Persistence\Transaction\UnitOfWorkInterface;

/**
 * Wraps handler execution in a database transaction.
 *
 * Replaces the old Connection-based implementation.
 * UnitOfWork::run() handles connection resilience internally —
 * no manual ping, reconnect, or isTransactionActive() checks needed here.
 *
 * ## Nesting prevention
 *
 * If ApplicationService has already opened a transaction via UnitOfWork::run(),
 * isActive() returns true and this middleware passes through without opening
 * a second transaction. The outer transaction owns the commit and rollback.
 *
 * This allows two usage patterns to coexist cleanly:
 *
 *   Pattern A — ApplicationService owns the transaction:
 *     ApplicationService::execute()
 *       → UnitOfWork::run() [outer transaction opens here]
 *         → EventBus::dispatch()
 *           → Kafka → ConsumerRunner
 *             → TransactionalMiddleware [isActive() = true, passes through]
 *               → Handler
 *
 *   Pattern B — Consumer owns the transaction (standalone handlers):
 *     ConsumerRunner
 *       → TransactionalMiddleware [isActive() = false, opens transaction here]
 *         → Handler
 *
 * ## Connection resilience
 *
 * Connection ping and reconnect happen inside UnitOfWork::run() before
 * beginTransaction(). This covers both patterns — whether the transaction
 * is opened by ApplicationService or by this middleware.
 */
final class TransactionalMiddleware implements MiddlewareInterface
{
    public function __construct(private UnitOfWorkInterface $unitOfWork) {}

    public function handle(Envelope $envelope, callable $next): Envelope
    {
        if ($this->unitOfWork->isActive()) {
            return $next($envelope);
        }

        return $this->unitOfWork->run(fn() => $next($envelope));
    }
}