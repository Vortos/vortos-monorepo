<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Hook;

use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Messaging\Hook\Attribute\PreSend;

#[PreSend(priority: 10)]
final class TenantHeaderHook
{
    public function __invoke(DomainEventInterface $event, array &$headers): void
    {
        $headers['X-Source-Service'] = $_ENV['APP_NAME'] ?? 'user-service';
        $headers['X-Schema-Version'] = (string) $event->eventVersion();
    }
}
