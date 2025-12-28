<?php

namespace App\User\Application\Projection;

use App\User\Domain\Event\UserCreatedEvent;
use Fortizan\Tekton\Persistence\Contract\ProjectionWriterInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class UserProjector 
{
    public function __construct(
        private ProjectionWriterInterface $writer
    ) {}

    #[AsMessageHandler]
    public function onUserCreated(UserCreatedEvent $event): void
    {
        $this->writer->upsert('user', $event->id, [
            'name' => $event->name,
            'email' => $event->email,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
    }
}
