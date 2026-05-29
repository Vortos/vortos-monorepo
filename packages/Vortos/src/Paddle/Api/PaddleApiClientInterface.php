<?php

declare(strict_types=1);

namespace Vortos\Paddle\Api;

use Paddle\SDK\Client;

interface PaddleApiClientInterface
{
    public function sdk(): Client;

    public function call(callable $operation): mixed;
}
