<?php

declare(strict_types=1);

return [
    'golden_rules' => [
        'Zero runtime reflection — all handler/policy/route/idempotency discovery happens at compile time via Symfony DI compiler passes. No class_exists(), no glob(), no attribute scanning at request time.',
        'Connection is always shared — DBAL Connection must be registered with setShared(true). Two Connection instances = two transactions = broken atomicity.',
        'CommandBus owns the transaction — handlers NEVER call beginTransaction(), commit(), or rollBack() directly. The TransactionalMiddleware wraps the entire handler invocation.',
        'Return the aggregate from command handlers — the bus calls pullDomainEvents() on the return value. A void return silently drops all domain events.',
        'Always upsert in projection handlers — Kafka delivers at-least-once. An INSERT throws on duplicate event replay. Use the persistence layer upsert() method.',
        'Store _id as string UUID in MongoDB — never ObjectId. All Vortos IDs are UUID v7 strings.',
        'cache->clear() uses SCAN not FLUSHDB — FLUSHDB destroys Kafka consumer group offsets stored in Redis. Always use the CacheInterface::clear() method which SCAN-deletes by prefix.',
        'CachePackage must be registered before MessagingPackage and CqrsPackage — both depend on CacheInterface being in the container before their extensions load.',
        'HttpPackage must be registered first overall — all other packages add event subscribers to its EventDispatcher instance.',
        'Always call cleanUp() after every request in worker mode (FrankenPHP) — ServicesResetter::reset() clears per-request state: identity, unit of work, correlation IDs, open connections.',
    ],

    'naming' => [
        'aggregate'          => 'PascalCase noun — User, Order, Product',
        'aggregate_id'       => 'Aggregate name + Id — UserId, OrderId, ProductId',
        'domain_event'       => 'PascalCase past tense + Event suffix — UserRegisteredEvent, OrderPlacedEvent, PaymentProcessedEvent',
        'command'            => 'PascalCase imperative, no suffix — RegisterUser, PlaceOrder, CancelSubscription',
        'command_handler'    => 'Command name + Handler — RegisterUserHandler, PlaceOrderHandler',
        'query'              => 'GetXByY or ListXs — GetUserById, GetOrderByReference, ListUserOrders',
        'query_handler'      => 'Query name + Handler — GetUserByIdHandler',
        'write_repository'   => 'Aggregate + Repository — UserRepository, OrderRepository',
        'read_repository'    => 'Aggregate + ReadRepository — UserReadRepository, OrderReadRepository',
        'controller'         => 'Action + Controller — RegisterUserController, PlaceOrderController',
        'request_dto'        => 'Action + Request — RegisterUserRequest, PlaceOrderRequest',
        'policy'             => 'Aggregate + Policy — UserPolicy, OrderPolicy',
        'permission_format'  => 'resource.action.scope — athletes.update.own, orders.read.all, payments.refund.own',
        'cache_key_format'   => '{entity}:{id}:{aspect} — user:123:profile, order:456:items',
        'consumer_group'     => 'kebab-case service name — user-service, order-service',
        'transport_name'     => 'kebab-case topic — user.events, order.placed, payment.processed',
    ],

    'package_registration_order' => [
        1  => 'HttpPackage — must be first, owns the EventDispatcher',
        2  => 'CachePackage — must be second, provides CacheInterface',
        3  => 'TracingPackage',
        4  => 'LoggerPackage',
        5  => 'MetricsPackage',
        6  => 'PersistencePackage',
        7  => 'DbalPersistencePackage',
        8  => 'MongoPersistencePackage',
        9  => 'CqrsPackage — depends on CacheInterface',
        10 => 'MessagingPackage — depends on CacheInterface',
        11 => 'AuthPackage',
        12 => 'AuthorizationPackage',
        13 => 'SecurityPackage',
        14 => 'TracingPackage (if using messaging tracing)',
        15 => 'McpPackage (dev only)',
    ],
];
