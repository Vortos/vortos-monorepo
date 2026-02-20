<?php

namespace App\User\Application\Projection;

use App\User\Domain\Event\UserCreatedEvent;
use App\User\Domain\Event\UserUpdatedEvent;
use Fortizan\Tekton\Bus\Event\Attribute\AsEventHandler;
use Fortizan\Tekton\Bus\Event\Attribute\EventHandler;
use Fortizan\Tekton\Bus\Event\Attribute\Header;
use Fortizan\Tekton\Bus\Projection\Attribute\ProjectionHandler;
use Fortizan\Tekton\Persistence\Contract\ProjectionWriterInterface;
use Psr\Log\LoggerInterface;

// #[AsEventHandler(group: 'async')]
class UserProjector 
{
    public function __construct(
        private ProjectionWriterInterface $writer
        ) {}
        
    // #[ProjectionHandler(priority:6)] 
    #[AsEventHandler(group: 'async', priority: 7)]
    public function __invoke(#[Header()] UserCreatedEvent $event, #[Header()] string $test = '1321'): void
    {
        $this->writer->upsert('users', $event->id, [
            'name' => $event->name,
            'email' => $event->email,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    // #[ProjectionHandler(priority:5)]
    #[AsEventHandler(group: 'async', priority: 8)]
    public function onUserDeleted(UserCreatedEvent $event): void
    {
        $this->writer->upsert('profile', $event->id, [
            'name' => $event->name . " Profile",
            'email' => $event->email,
            'synced_at' => date('Y-m-d H:i:s')
        ]);
    }
}
