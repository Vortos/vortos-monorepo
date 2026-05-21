<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging\Hook;

use Vortos\Domain\Event\EventEnvelope;
use Vortos\Messaging\Hook\Attribute\PreSend;

#[PreSend(priority: 10)]
final class TenantHeaderHook
{
    public function __invoke(EventEnvelope $envelope, array &$headers): void
    {
        $headers['X-Source-Service'] = $_ENV['APP_NAME'] ?? 'user-service';
        $headers['X-Schema-Version'] = (string) $envelope->schemaVersion;
    }
}
