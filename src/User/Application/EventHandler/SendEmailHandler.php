<?php

namespace App\User\Application\EventHandler;

use App\User\Domain\Event\UserCreatedEvent;
use Fortizan\Tekton\Bus\Event\Attribute\EventHandler;
use Psr\Log\LoggerInterface;

#[EventHandler(group:'async', retries:2, delay:2000)]
class SendEmailHandler
{
    public function __construct(
        private LoggerInterface $logger
    ){
    }

    public function __invoke(UserCreatedEvent $event)
    {
        $this->logger->warning("Sending Email...........");
        echo "Sending Email....";
    }
}