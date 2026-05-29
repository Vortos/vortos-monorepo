<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

interface PaddleOutboxWriterInterface
{
    public function queue(string $operation, array $payload): void;
}
