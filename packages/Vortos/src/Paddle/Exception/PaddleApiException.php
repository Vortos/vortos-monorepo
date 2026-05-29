<?php

declare(strict_types=1);

namespace Vortos\Paddle\Exception;

class PaddleApiException extends PaddleException
{
    public function __construct(
        string               $message,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorType = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
