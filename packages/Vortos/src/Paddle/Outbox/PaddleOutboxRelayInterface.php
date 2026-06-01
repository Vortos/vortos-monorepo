<?php

declare(strict_types=1);

namespace Vortos\Paddle\Outbox;

interface PaddleOutboxRelayInterface
{
    public function relay(): int;
}
