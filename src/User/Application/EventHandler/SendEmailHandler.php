<?php

declare(strict_types=1);

namespace App\User\Application\EventHandler;

use App\User\Domain\Event\UserCreatedEvent;
use Doctrine\DBAL\Connection;
use Exception;
use Vortos\Messaging\Attribute\AsEventHandler;
use Psr\Log\LoggerInterface;

#[AsEventHandler(handlerId: 'user.created.handler', consumer: 'user.events', idempotent: false)]
final class SendEmailHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private Connection $connection
    ) {}

    public function __invoke(UserCreatedEvent $event): void
    {
        $this->logger->info('UserCreatedHandler executed adoooooo', [
            'userId' => (string) $event->id,
            'email'  => $event->email,
        ]);

        $this->connection->insert('test_events', [
            'user_id' => (string) $event->id,
            'email'   => $event->email,
        ]);
        // throw new Exception("test test test test test test tset");
    }
}
