<?php

declare(strict_types=1);

return [
    'layers' => [
        'Domain' => [
            'responsibility' => 'Pure business logic. No infrastructure, no framework, no I/O.',
            'contains'       => ['Aggregates', 'Domain Events', 'Value Objects', 'Domain Exceptions', 'Repository Interfaces'],
            'rules'          => [
                'No Doctrine annotations or attributes',
                'No framework dependencies — only PHP and vortos/domain base classes',
                'Aggregates extend AggregateRoot and record domain events via recordEvent()',
                'Repository interfaces defined here, implementations in Infrastructure',
            ],
        ],
        'Application' => [
            'responsibility' => 'Orchestrates domain objects. Handles commands, queries, and projections.',
            'contains'       => ['Command Handlers', 'Query Handlers', 'Projection Handlers', 'Application Services'],
            'rules'          => [
                'Command handlers: accept a Command DTO, load aggregate, call business method, return aggregate',
                'Command handlers NEVER call beginTransaction/commit — the CommandBus wraps them',
                'Query handlers: accept a Query DTO, read from the read store, return a DTO or array',
                'Projection handlers: accept a DomainEvent, update the read model via upsert()',
                'No HTTP, no JSON, no request/response objects',
            ],
        ],
        'Infrastructure' => [
            'responsibility' => 'Implements interfaces defined in Domain. All I/O lives here.',
            'contains'       => ['Write Repositories (DBAL)', 'Read Repositories (MongoDB)', 'Messaging Config', 'External Service Clients'],
            'rules'          => [
                'Write repositories use Doctrine DBAL — never Doctrine ORM for the write side',
                'Read repositories use MongoDB driver directly',
                'MessagingConfig classes declare producers and consumers using #[RegisterProducer] and #[RegisterConsumer]',
                'No domain logic — only persistence and transport concerns',
            ],
        ],
        'Representation' => [
            'responsibility' => 'HTTP entry points and authorization policies.',
            'contains'       => ['Controllers', 'Request DTOs', 'Policies'],
            'rules'          => [
                'Controllers are thin — validate request, dispatch Command or Query, return response',
                'Controllers must NOT contain business logic',
                'One policy per aggregate/resource — compile error if two policies cover the same resource',
                'Policies receive CurrentUser and the resource, return allow/deny',
            ],
        ],
    ],

    'cqrs_flow' => [
        'command_flow' => [
            'HTTP Request → Controller → Command DTO',
            'CommandBus: validate → idempotency check → begin transaction',
            'CommandHandler: load aggregate → call domain method → return aggregate',
            'CommandBus: pullDomainEvents() from aggregate',
            'CommandBus: commit transaction',
            'CommandBus: dispatch each domain event to EventBus',
            'EventBus: write event to vortos_outbox (same DB transaction as domain write)',
            'OutboxRelayWorker: poll vortos_outbox → produce to Kafka',
        ],
        'query_flow' => [
            'HTTP Request → Controller → Query DTO',
            'QueryBus: route to QueryHandler',
            'QueryHandler: read from MongoDB read repository → return DTO/array',
            'Controller: serialize and return JsonResponse',
        ],
        'projection_flow' => [
            'Kafka → ConsumerWorker → deserialize event',
            'ConsumerRunner: route to ProjectionHandler via #[AsProjectionHandler]',
            'ProjectionHandler: upsert read model in MongoDB',
            'ConsumerWorker: commit Kafka offset',
        ],
    ],

    'file_structure' => <<<'STRUCTURE'
A bounded context lives in src/{Context}/:

src/
  Orders/
    Domain/
      Order.php                                ← extends AggregateRoot
      OrderId.php                              ← extends AggregateId
      OrderStatus.php                          ← ValueObject (enum or class)
      Event/
        OrderPlacedEvent.php                   ← extends DomainEvent
        OrderCancelledEvent.php
      Exception/
        OrderNotFoundException.php
        OrderAlreadyCancelledException.php
      Repository/
        OrderRepositoryInterface.php           ← interface only — implementation in Infrastructure
    Application/
      Command/
        PlaceOrder/
          PlaceOrder.php                       ← Command DTO (readonly, no logic)
          PlaceOrderHandler.php                ← #[AsCommandHandler], returns Order aggregate
        CancelOrder/
          CancelOrder.php
          CancelOrderHandler.php
      Query/
        GetOrderById/
          GetOrderById.php                     ← Query DTO
          GetOrderByIdHandler.php              ← reads from MongoDB
        ListOrders/
          ListOrders.php
          ListOrdersHandler.php
      Projection/
        OrderPlacedProjection.php              ← #[AsProjectionHandler], upserts MongoDB
        OrderCancelledProjection.php
    Infrastructure/
      Persistence/
        OrderRepository.php                    ← implements OrderRepositoryInterface, uses DBAL
        OrderReadRepository.php                ← MongoDB reads
      Messaging/
        OrderMessagingConfig.php               ← #[RegisterProducer], #[RegisterConsumer]
    Representation/
      Controller/
        PlaceOrderController.php               ← #[AsRoute], dispatches PlaceOrder command
        PlaceOrderRequest.php                  ← request DTO with validation
        GetOrderController.php
      Policy/
        OrderPolicy.php                        ← #[AsPolicy], one per aggregate
STRUCTURE,

    'transaction_boundary' => [
        'owned_by' => 'CommandBus via TransactionalMiddleware',
        'scope'    => 'Entire command handler invocation — domain write + outbox write are atomic',
        'rule'     => 'Handlers call $this->repository->save($aggregate). Never beginTransaction/commit directly.',
        'worker_mode_note' => 'In FrankenPHP worker mode, each request gets a fresh unit of work. cleanUp() after the response resets state for the next request.',
    ],
];
