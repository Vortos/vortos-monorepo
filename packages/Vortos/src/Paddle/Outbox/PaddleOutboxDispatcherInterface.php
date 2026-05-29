<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

interface PaddleOutboxDispatcherInterface
{
    public function dispatch(string $operation, array $payload): void;
}
