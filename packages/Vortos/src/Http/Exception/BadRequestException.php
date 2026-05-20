<?php

declare(strict_types=1);

namespace Vortos\Http\Exception;

class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }
}
