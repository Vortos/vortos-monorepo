<?php

namespace Vortos\Domain\Query;

/**
 * Marker contract for all queries in the Vortos CQRS system.
 *
 * Queries express questions — they are named in the descriptive mood
 * (GetUser, ListActiveOrders, FindBookingsByDate). They carry filter
 * criteria as constructor properties and never mutate state.
 *
 * Queries are dispatched through QueryBusInterface::ask() and handled
 * by exactly one QueryHandlerInterface implementation discovered via
 * the #[AsQueryHandler] attribute.
 *
 * Queries always return a value — a ViewModel, array, or scalar.
 */
interface QueryInterface
{
    // Marker interface — no methods required.
    // Queries may have any constructor parameters as query criteria.
    // 
    // Example:
    //   final readonly class GetUserQuery implements QueryInterface
    //   {
    //       public function __construct(
    //           public readonly string $userId,
    //       ) {}
    //   }
}